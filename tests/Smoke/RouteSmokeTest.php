<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Requests every GET route of the running application over HTTP, anonymously
 * and as the development administrator. It talks to the web server rather
 * than the framework, so the same suite checks route parity throughout the
 * Fat-Free to Symfony migration.
 *
 * Fixtures (a message, template and archive) are created in the development
 * database and removed afterwards. Run with: bin/dev test --testsuite smoke
 */
final class RouteSmokeTest extends TestCase
{
    private static PDO $db;
    private static string $baseUrl;
    /** @var array<string, string> */
    private static array $fixture = [];
    private static ?\CurlHandle $adminClient = null;
    private static string $startedAt;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim((string) getenv('SMOKE_BASE_URL'), '/');
        self::$db = new PDO(
            (string) getenv('SMOKE_DB_DSN'),
            (string) getenv('SMOKE_DB_USER'),
            (string) getenv('SMOKE_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // The application writes timestamps in PHP's timezone, not the database's.
        self::$startedAt = date('Y-m-d H:i:s');

        $admin = self::$db->prepare('SELECT s_id, s_uuid FROM subscribers WHERE LOWER(s_email) = LOWER(?)');
        $admin->execute([(string) getenv('SMOKE_ADMIN_EMAIL')]);
        $row = $admin->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            self::fail('Development administrator not found; run bin/dev seed-admin.');
        }

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
        )->execute([$muid, (int) $row['s_id']]);

        self::$fixture = [
            'subscriberId' => (string) $row['s_id'],
            'token' => (string) $row['s_uuid'],
            'shortcode' => 'ALL',
            'muid' => $muid,
            'tid' => (string) $templateId,
            'aid' => (string) $archiveId,
        ];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixture === []) {
            return;
        }
        self::$db->prepare('DELETE FROM smlog WHERE sml_muid = ?')->execute([self::$fixture['muid']]);
        self::$db->prepare('DELETE FROM messages WHERE m_uniqid = ?')->execute([self::$fixture['muid']]);
        self::$db->prepare('DELETE FROM templates WHERE t_id = ?')->execute([(int) self::$fixture['tid']]);
        self::$db->prepare('DELETE FROM archives WHERE a_id = ?')->execute([(int) self::$fixture['aid']]);
        self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')
            ->execute([(int) self::$fixture['subscriberId'], self::$startedAt]);
        self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')
            ->execute([(int) self::$fixture['subscriberId'], self::$startedAt]);
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
        [$status, $body] = self::request(curl_init(), $route);
        self::assertSame(200, $status, $route);
        self::assertNoPhpErrors($route, $body);
    }

    #[DataProvider('loginRequiredRoutes')]
    public function testLoginRequiredRouteRedirectsAnonymous(string $route): void
    {
        [$status, , $location] = self::request(curl_init(), $route);
        self::assertSame(302, $status, $route);
        self::assertStringEndsWith('/login', $location, $route);
    }

    #[DataProvider('adminRoutes')]
    public function testAdminRouteIsForbiddenAnonymously(string $route): void
    {
        [$status, $body] = self::request(curl_init(), $route);
        self::assertSame(403, $status, $route);
        self::assertNoPhpErrors($route, $body);
    }

    #[DataProvider('allRoutes')]
    public function testRouteRendersForAdministrator(string $route): void
    {
        [$status, $body, $location] = self::request(self::adminClient(), $route);
        // /profile hands the administrator on to their own profile page.
        self::assertContains($status, [200, 302], $route);
        if ($status === 302) {
            self::assertStringNotContainsString('/login', $location, $route);
        }
        self::assertNoPhpErrors($route, $body);
    }

    public function testUnknownRouteIsNotFound(): void
    {
        [$status] = self::request(curl_init(), '/no-such-route-' . bin2hex(random_bytes(4)));
        self::assertSame(404, $status);
    }

    /** One curl handle keeps the administrator's cookies across requests. */
    private static function adminClient(): \CurlHandle
    {
        if (self::$adminClient !== null) {
            return self::$adminClient;
        }
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::$db->prepare(
            "INSERT INTO auth_login_tokens (alt_s_id, alt_email, alt_token_hash, alt_expires_at)
             SELECT s_id, s_email, ?, ? FROM subscribers WHERE s_id = ?"
        )->execute([hash('sha256', $rawToken), date('Y-m-d H:i:s', time() + 600), (int) self::$fixture['subscriberId']]);

        $client = curl_init();
        curl_setopt($client, CURLOPT_COOKIEFILE, '');
        [$status, , $location] = self::request($client, '/auth/verify?token=' . $rawToken);
        if ($status !== 302 || str_ends_with($location, '/login')) {
            self::fail("Administrator magic-link login failed (HTTP {$status}, Location {$location}).");
        }
        return self::$adminClient = $client;
    }

    /** @return array{int, string, string} status, body, Location header */
    private static function request(\CurlHandle $client, string $route): array
    {
        $path = preg_replace_callback(
            '/\{(\w+)\}/',
            static fn(array $m): string => rawurlencode(self::$fixture[$m[1]]),
            $route
        );
        $location = '';
        curl_setopt_array($client, [
            CURLOPT_URL => self::$baseUrl . $path,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($client);
        if ($body === false) {
            self::fail("Request to {$path} failed: " . curl_error($client));
        }
        return [(int) curl_getinfo($client, CURLINFO_HTTP_CODE), (string) $body, $location];
    }

    private static function assertNoPhpErrors(string $route, string $body): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>|Uncaught |Stack trace:/',
            $body,
            "PHP error output on {$route}"
        );
    }
}
