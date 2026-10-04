<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Requests every GET route of the running application over HTTP, anonymously
 * and as the development administrator. It talks to the web server rather
 * than the framework, so the same suite checks route parity throughout the
 * Fat-Free to Symfony migration.
 *
 * Fixtures (a message, template and archive) are created in the development
 * database and removed afterwards. Run with: bin/dev test --testsuite smoke
 */
final class RouteSmokeTest extends SmokeTestCase
{
    /** @var array<string, string> */
    private static array $fixture = [];
    private static ?\CurlHandle $adminClient = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $archiveId = (int) self::$db->query(
            "INSERT INTO archives (a_subject, a_html) VALUES ('Smoke fixture', '<p>Smoke fixture</p>') RETURNING a_id"
        )->fetchColumn();
        $templateId = (int) self::$db->query(
            "INSERT INTO templates (t_name, t_html, t_text) VALUES ('Smoke fixture', '<p>{content}</p>', '{content}') RETURNING t_id"
        )->fetchColumn();
        $muid = bin2hex(random_bytes(16));
        $insert = self::$db->prepare(
            "INSERT INTO messages (m_uniqid, m_subject, m_html, m_text, m_a_id) VALUES (?, 'Smoke fixture', '<p>Smoke</p>', 'Smoke', ?)"
        );
        $insert->execute([$muid, $archiveId]);
        // Recipient-only routes (resend, reactions) need the message to have
        // been delivered to the subscriber.
        self::$db->prepare(
            "INSERT INTO smlog (sml_s_uuid, sml_email, sml_muid, sml_list_shortcode, sml_date_sent)
             SELECT s_uuid, s_email, ?, 'ALL', LOCALTIMESTAMP FROM subscribers WHERE s_id = ?"
        )->execute([$muid, self::$admin['s_id']]);

        self::$fixture = [
            'token' => self::$admin['s_uuid'],
            'shortcode' => 'ALL',
            'muid' => $muid,
            'tid' => (string) $templateId,
            'aid' => (string) $archiveId,
        ];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture !== []) {
            self::$db->prepare('DELETE FROM smlog WHERE sml_muid = ?')->execute([self::$fixture['muid']]);
            self::$db->prepare('DELETE FROM messages WHERE m_uniqid = ?')->execute([self::$fixture['muid']]);
            self::$db->prepare('DELETE FROM templates WHERE t_id = ?')->execute([(int) self::$fixture['tid']]);
            self::$db->prepare('DELETE FROM archives WHERE a_id = ?')->execute([(int) self::$fixture['aid']]);
        }
        parent::tearDownAfterClass();
    }

    /** Routes open to everyone. */
    private const PUBLIC = [
        '/', '/index', '/home', '/privacy', '/login', '/store', '/subscribe',
        '/archives', '/archives/1', '/archive/{aid}', '/archive/{aid}/{token}/{muid}',
        '/profile/subscriber/{token}',
        '/confirm/{token}/{shortcode}', '/confirm/{token}/{shortcode}/{muid}',
        '/unsubscribe/{token}/{shortcode}', '/unsubscribe/{token}/{shortcode}/{muid}',
        '/forward/{token}/{muid}', '/resend/{token}/{muid}',
        '/like/{token}/{muid}', '/dislike/{token}/{muid}',
        '/subscribe/{token}', '/subscribe/{token}/{muid}',
        '/contact-form/{token}', '/contact-form/{token}/{muid}',
        '/ut/{token}/{muid}',
    ];

    /** Routes that send anonymous visitors to the login page. */
    private const LOGIN_REQUIRED = [
        '/profile', '/edit-profile', '/my/messages', '/contact-form',
    ];

    /** Routes that require an administrator or an ACL permission. */
    private const ADMIN = [
        '/subscribers', '/subscribers/1', '/activesubscribers', '/activesubscribers/1',
        '/bulk-subscribe', '/bulk-unsubscribe', '/import', '/export', '/export/0/10', '/sync',
        '/messages', '/messages/1', '/message', '/message/{muid}', '/forward/{muid}',
        '/templates', '/templates/1', '/template', '/template/{tid}',
        '/lists', '/roles',
        '/advanced-queue', '/queuelist/{muid}', '/queue', '/queue/1',
        '/processqueue', '/processqueue/{muid}', '/processqueue/{muid}/10',
        '/stop-send', '/sendtome/{muid}',
        '/sendlog', '/sendlog/1', '/sitelog', '/sitelog/1',
        '/message-views/{muid}', '/message-views/{muid}/1',
    ];

    /** @return iterable<string, array{string}> */
    public static function publicRoutes(): iterable
    {
        foreach (self::PUBLIC as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function loginRequiredRoutes(): iterable
    {
        foreach (self::LOGIN_REQUIRED as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function adminRoutes(): iterable
    {
        foreach (self::ADMIN as $route) {
            yield $route => [$route];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function allRoutes(): iterable
    {
        yield from self::publicRoutes();
        yield from self::loginRequiredRoutes();
        yield from self::adminRoutes();
    }

    #[DataProvider('publicRoutes')]
    public function testPublicRouteRendersAnonymously(string $route): void
    {
        $response = self::request(self::client(), 'GET', self::path($route));
        self::assertSame(200, $response['status'], $route);
        self::assertCleanPage($route, $response['body']);
    }

    #[DataProvider('loginRequiredRoutes')]
    public function testLoginRequiredRouteRedirectsAnonymous(string $route): void
    {
        $response = self::request(self::client(), 'GET', self::path($route));
        self::assertSame(302, $response['status'], $route);
        self::assertStringEndsWith('/login', $response['location'], $route);
    }

    #[DataProvider('adminRoutes')]
    public function testAdminRouteIsForbiddenAnonymously(string $route): void
    {
        $response = self::request(self::client(), 'GET', self::path($route));
        self::assertSame(403, $response['status'], $route);
        self::assertCleanPage($route, $response['body']);
    }

    #[DataProvider('allRoutes')]
    public function testRouteRendersForAdministrator(string $route): void
    {
        $response = self::request(self::adminClient(), 'GET', self::path($route));
        // /profile hands the administrator on to their own profile page.
        self::assertContains($response['status'], [200, 302], $route);
        if ($response['status'] === 302) {
            self::assertStringNotContainsString('/login', $response['location'], $route);
        }
        self::assertCleanPage($route, $response['body']);
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $response = self::request(self::client(), 'GET', '/no-such-route-' . bin2hex(random_bytes(4)));
        self::assertSame(404, $response['status']);
    }

    /** One client keeps the administrator's cookies across requests. */
    private static function adminClient(): \CurlHandle
    {
        if (self::$adminClient === null) {
            self::$adminClient = self::client();
            self::loginAsAdmin(self::$adminClient);
        }
        return self::$adminClient;
    }

    /** Fill {placeholders} in a route with the fixture values. */
    private static function path(string $route): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn(array $m): string => rawurlencode(self::$fixture[$m[1]]),
            $route
        );
    }
}
