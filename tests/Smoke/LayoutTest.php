<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * The shared Twig layout: assets, the sticky header, navigation for anonymous
 * visitors, subscribers, administrators and custom roles, CKEditor loading,
 * the footer and the error pages.
 */
final class LayoutTest extends SmokeTestCase
{
    /** Persistent fixture subscriber for the navigation tests. */
    private const NAV_EMAIL = 'smoke-nav@ctnlist.test';

    /** Admin bar links and the permission each requires. */
    private const ADMIN_LINKS = [
        '/messages' => 'messages.manage',
        '/message' => 'messages.manage',
        '/templates' => 'templates.manage',
        '/template' => 'templates.manage',
        '/queue' => 'queue.process',
        '/advanced-queue' => 'messages.queue',
        '/processqueue' => 'queue.process',
        '/stop-send' => 'queue.process',
        '/subscribers' => 'subscribers.view',
        '/activesubscribers' => 'subscribers.view',
        '/bulk-subscribe' => 'subscribers.manage',
        '/bulk-unsubscribe' => 'subscribers.manage',
        '/import' => 'subscribers.manage',
        '/export' => 'subscribers.manage',
        '/sync' => 'subscribers.manage',
        '/address-validation' => 'subscribers.manage',
        '/lists' => 'lists.manage',
        '/sendlog' => 'logs.view',
        '/sitelog' => 'logs.view',
        '/delivery' => 'logs.view',
        '/roles' => 'roles.manage',
        '/settings' => 'settings.manage',
    ];

    private static int $navId;
    private static string $roleKey;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->prepare('INSERT INTO subscribers (s_email, s_fname, s_lname) VALUES (?, ?, ?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')
            ->execute([self::NAV_EMAIL, 'Nav', 'Tester']);
        $id = self::$db->prepare('SELECT s_id FROM subscribers WHERE s_email = ?');
        $id->execute([self::NAV_EMAIL]);
        self::$navId = (int) $id->fetchColumn();
        self::$roleKey = 'smoke-nav-' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        // Only the test's own role: the fixture keeps its system Subscriber role.
        self::$db->prepare('DELETE FROM subscriber_roles WHERE sr_r_id IN (SELECT r_id FROM roles WHERE r_key = ?)')->execute([self::$roleKey]);
        self::$db->prepare('DELETE FROM roles WHERE r_key = ?')->execute([self::$roleKey]);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')->execute([self::$navId, self::$startedAt]);
        self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')->execute([self::$navId, self::$startedAt]);
        parent::tearDownAfterClass();
    }

    public function testAnonymousLayout(): void
    {
        $body = self::request(self::client(), 'GET', '/')['body'];
        self::assertMatchesRegularExpression('#<img class="app-avatar" src="/assets/images/avatar-placeholder-[^"]+\.svg" alt=""#', $body, 'signed out: the placeholder picture beside Log in');
        self::assertMatchesRegularExpression('/<title>.+ \| Home<\/title>/', $body);
        self::assertMatchesRegularExpression('/<header class="[^"]*\bsticky-top\b/', $body, 'sticky header');
        self::assertStringContainsString('aria-label="Main navigation"', $body);
        foreach (['/', '/subscribe', '/contact-form', '/login'] as $href) {
            self::assertStringContainsString('href="' . $href . '"', $body, "public link {$href}");
        }
        // Archives appear only while public archives are enabled: the Settings override, else .env.
        $override = self::value("SELECT o_value FROM options WHERE o_key = 'setting:APP_ARCHIVE_ENABLED'");
        $archives = filter_var($override !== false ? $override : ($_ENV['APP_ARCHIVE_ENABLED'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);
        self::assertSame($archives, str_contains($body, 'href="/archives"'), 'Archives link follows APP_ARCHIVE_ENABLED');
        self::assertMatchesRegularExpression('/class="nav-link active" href="\/" aria-current="page"/', $body, 'current page marked');
        self::assertStringNotContainsString('aria-label="Administration"', $body);
        self::assertStringNotContainsString('action="/logout"', $body);
        self::assertStringNotContainsString('btn-toolbar', $body, 'no secondary toolbar');
    }

    public function testSharedAssets(): void
    {
        $client = self::client();
        $body = self::request($client, 'GET', '/privacy')['body'];

        self::assertDoesNotMatchRegularExpression('/unify-|fontawesome|font-awesome|jquery|fonts\.googleapis|ckeditor|es-module-shims/i', $body);
        self::assertDoesNotMatchRegularExpression('/\bfa[srb]? fa-|\bg-(color|bg|py|pa|mb|font)-|\bu-(btn|heading|icon)/', $body, 'no Font Awesome or Unify classes');
        self::assertStringNotContainsString('X-UA-Compatible', $body);
        self::assertStringNotContainsString('rc-webs', $body);
        self::assertStringNotContainsString('id="datetime"', $body, 'no live clock');
        self::assertStringContainsString('bootstrap@5.3.8/dist/css/bootstrap.min.css', $body);
        self::assertStringContainsString('bootstrap-icons@', $body);
        self::assertStringContainsString('<script type="importmap">', $body);

        foreach (['/assets/styles/app-[^"]+\.css', '/assets/app-[^"]+\.js', '/assets/images/favicon-[^"]+\.svg'] as $pattern) {
            $match = self::match('#"(' . $pattern . ')"#', $body, $pattern);
            self::assertSame(200, self::request($client, 'GET', $match[1])['status'], $match[1]);
        }
        self::assertMatchesRegularExpression('#<link rel="icon" href="/assets/images/favicon-[^"]+\.svg"#', $body);
        self::assertStringContainsString('<footer class="app-footer', $body);
        self::assertStringContainsString('href="/privacy"', $body);
    }

    public function testSubscriberNavigation(): void
    {
        $client = self::signIn(self::$navId);
        $body = self::request($client, 'GET', '/edit-profile')['body'];

        self::assertStringContainsString('Nav Tester', $body, 'account menu');
        foreach (['/profile', '/my/messages', '/edit-profile'] as $href) {
            self::assertStringContainsString('href="' . $href . '"', $body, "account link {$href}");
        }
        self::assertMatchesRegularExpression('#<form method="post" action="/logout">\s*<input type="hidden" name="csrf"#', $body, 'log out is a POST with the CSRF token');
        self::assertStringNotContainsString('href="/login"', $body);
        self::assertStringNotContainsString('aria-label="Administration"', $body, 'no permissions, no admin bar');
    }

    public function testAdministratorNavigation(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $body = self::request($client, 'GET', '/lists')['body'];

        self::assertMatchesRegularExpression('/<title>.+ \| Lists<\/title>/', $body);
        self::assertStringContainsString('aria-label="Administration"', $body);
        foreach (array_keys(self::ADMIN_LINKS) as $href) {
            self::assertStringContainsString('href="' . $href . '"', $body, "admin link {$href}");
        }
        self::assertMatchesRegularExpression('/All subscribers<\/span>\s*<span class="badge[^"]*">\d+</', $body, 'counters');
        self::assertStringContainsString('action="/logout"', $body);
        self::assertStringContainsString('Create a topic list', $body, 'page content inside the layout');
        self::assertStringNotContainsString('btn-toolbar', $body, 'no duplicate admin toolbar');
    }

    /** A custom role sees exactly the tools its permissions allow; the controllers still enforce them. */
    public function testCustomRoleNavigation(): void
    {
        self::$db->prepare('INSERT INTO roles (r_key, r_name) VALUES (?, ?)')->execute([self::$roleKey, 'Smoke navigation ' . self::$roleKey]);
        self::$db->prepare(
            "INSERT INTO role_permissions (rp_r_id, rp_ap_id)
             SELECT r.r_id, ap.ap_id FROM roles r JOIN acl_permissions ap ON ap.ap_key IN ('logs.view', 'subscribers.view') WHERE r.r_key = ?"
        )->execute([self::$roleKey]);
        self::$db->prepare('INSERT INTO subscriber_roles (sr_s_id, sr_r_id) SELECT ?, r_id FROM roles WHERE r_key = ?')->execute([self::$navId, self::$roleKey]);

        $client = self::signIn(self::$navId);
        $body = self::request($client, 'GET', '/sendlog')['body'];

        self::assertStringContainsString('aria-label="Administration"', $body);
        foreach (self::ADMIN_LINKS as $href => $permission) {
            $granted = in_array($permission, ['logs.view', 'subscribers.view'], true);
            $method = $granted ? 'assertStringContainsString' : 'assertStringNotContainsString';
            self::$method('href="' . $href . '"', $body, "{$href} ({$permission})");
        }
        self::assertSame(200, self::request($client, 'GET', '/subscribers')['status']);
        self::assertSame(403, self::request($client, 'GET', '/messages')['status'], 'hidden and still forbidden');
        self::assertSame(403, self::request($client, 'GET', '/bulk-subscribe')['status']);
    }

    /**
     * Each permission on its own: a custom role holding only it (no
     * Administrator) sees and opens exactly that permission's pages, and the
     * server refuses every other administration page.
     */
    public function testEachPermissionOpensExactlyItsPages(): void
    {
        self::$db->prepare('INSERT INTO roles (r_key, r_name) VALUES (?, ?) ON CONFLICT DO NOTHING')->execute([self::$roleKey, 'Smoke navigation ' . self::$roleKey]);
        self::$db->prepare('INSERT INTO subscriber_roles (sr_s_id, sr_r_id) SELECT ?, r_id FROM roles WHERE r_key = ? ON CONFLICT DO NOTHING')->execute([self::$navId, self::$roleKey]);
        $client = self::signIn(self::$navId);
        foreach (array_unique(array_values(self::ADMIN_LINKS)) as $permission) {
            self::$db->prepare('DELETE FROM role_permissions WHERE rp_r_id IN (SELECT r_id FROM roles WHERE r_key = ?)')->execute([self::$roleKey]);
            self::$db->prepare('INSERT INTO role_permissions (rp_r_id, rp_ap_id) SELECT r.r_id, ap.ap_id FROM roles r JOIN acl_permissions ap ON ap.ap_key = ? WHERE r.r_key = ?')
                ->execute([$permission, self::$roleKey]);
            $menu = self::request($client, 'GET', '/edit-profile')['body'];
            foreach (self::ADMIN_LINKS as $href => $required) {
                $status = self::request($client, 'GET', $href)['status'];
                if ($required === $permission) {
                    self::assertSame(200, $status, "{$href} with only {$permission}");
                    self::assertStringContainsString('href="' . $href . '"', $menu, "{$href} offered with {$permission}");
                } else {
                    self::assertSame(403, $status, "{$href} needs {$required}, not {$permission}");
                    self::assertStringNotContainsString('href="' . $href . '"', $menu, "{$href} hidden with only {$permission}");
                }
            }
        }
    }

    public function testEditorAssetsOnlyOnEditorPages(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);

        foreach (['/message', '/template'] as $page) {
            $body = self::request($client, 'GET', $page)['body'];
            self::assertStringContainsString('src="/vendor/ckeditor5/ckeditor5.umd.js"', $body, $page);
            self::assertStringContainsString('href="/vendor/ckeditor5/ckeditor5.css"', $body, $page);
            $editor = self::match('#src="(/assets/ckeditor/editor-[^"]+\.js)"#', $body, $page);
            self::assertStringContainsString('data-html-editor', $body, $page);
        }
        self::assertSame(200, self::request($client, 'GET', $editor[1])['status']);
        self::assertSame(200, self::request($client, 'GET', '/vendor/ckeditor5/ckeditor5.umd.js')['status']);

        foreach (['/messages', '/lists', '/'] as $page) {
            self::assertStringNotContainsStringIgnoringCase('ckeditor', self::request($client, 'GET', $page)['body'], $page);
        }
    }

    /** The defunct Ecwid store page and subscription endpoint are gone. */
    public function testStoreAndEcwidRemoved(): void
    {
        self::assertSame(404, self::request(self::client(), 'GET', '/store')['status']);
        self::assertSame(404, self::request(self::client(), 'POST', '/ecwid-subscribe', ['email' => 'nobody@example.com'])['status']);
        $client = self::client();
        self::loginAsAdmin($client);
        foreach ([self::request(self::client(), 'GET', '/')['body'], self::request($client, 'GET', '/messages')['body']] as $body) {
            self::assertStringNotContainsString('href="/store"', $body);
            self::assertDoesNotMatchRegularExpression('/ecwid|>\s*Store\s*</i', $body);
        }
    }

    public function testErrorPages(): void
    {
        $forbidden = self::request(self::client(), 'GET', '/lists');
        self::assertSame(403, $forbidden['status']);
        self::assertStringContainsString('Access denied.', $forbidden['body']);
        self::assertStringContainsString('href="/login"', $forbidden['body'], 'in the site layout');

        $missing = self::request(self::client(), 'GET', '/no-such-page');
        self::assertSame(404, $missing['status']);
        self::assertStringContainsString('<h1>Not Found</h1>', $missing['body']);
    }

    private static function signIn(int $subscriberId): \CurlHandle
    {
        $client = self::client();
        $response = self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken($subscriberId));
        self::assertSame(302, $response['status']);
        return $client;
    }
}
