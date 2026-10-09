<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * The Settings page: access by permission, overriding and resetting values,
 * source badges and secrets. Each test starts without overrides; the
 * installation's own overrides are restored afterwards.
 */
final class SettingsPageTest extends SmokeTestCase
{
    private const STAFF_EMAIL = 'smoke-settings@ctnlist.test';

    private static int $staffId;
    private static string $roleKey;

    /** @var list<array<string, mixed>> */
    private array $saved = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->prepare('INSERT INTO subscribers (s_email) VALUES (?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute([self::STAFF_EMAIL]);
        $id = self::$db->prepare('SELECT s_id FROM subscribers WHERE s_email = ?');
        $id->execute([self::STAFF_EMAIL]);
        self::$staffId = (int) $id->fetchColumn();
        self::$roleKey = 'smoke-settings-' . bin2hex(random_bytes(3));
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM subscriber_roles WHERE sr_r_id IN (SELECT r_id FROM roles WHERE r_key = ?)')->execute([self::$roleKey]);
        self::$db->prepare('DELETE FROM roles WHERE r_key = ?')->execute([self::$roleKey]);
        self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')->execute([self::$staffId, self::$startedAt]);
        self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')->execute([self::$staffId, self::$startedAt]);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = self::rows("SELECT * FROM options WHERE o_key LIKE 'setting:%'");
        // Start from .env alone; the installation's own overrides are put back in tearDown().
        self::$db->exec("DELETE FROM options WHERE o_key LIKE 'setting:%'");
    }

    protected function tearDown(): void
    {
        self::$db->exec("DELETE FROM options WHERE o_key LIKE 'setting:%'");
        $restore = self::$db->prepare('INSERT INTO options (o_key, o_value, o_secret, o_updated_at, o_updated_by_s_id) VALUES (?, ?, ?, ?, ?)');
        foreach ($this->saved as $row) {
            $restore->execute([$row['o_key'], $row['o_value'], $row['o_secret'] ? 'true' : 'false', $row['o_updated_at'], $row['o_updated_by_s_id']]);
        }
        parent::tearDown();
    }

    public function testAccessFollowsSettingsManage(): void
    {
        self::assertSame(403, self::request(self::client(), 'GET', '/settings')['status'], 'anonymous');

        $subscriber = self::signIn();
        self::assertSame(403, self::request($subscriber, 'GET', '/settings')['status'], 'ordinary subscriber');
        self::assertStringNotContainsString('href="/settings"', self::request($subscriber, 'GET', '/profile/subscriber/' . self::uuid())['body']);

        self::$db->prepare('INSERT INTO roles (r_key, r_name) VALUES (?, ?)')->execute([self::$roleKey, 'Smoke settings ' . self::$roleKey]);
        self::$db->prepare("INSERT INTO role_permissions (rp_r_id, rp_ap_id) SELECT r.r_id, ap.ap_id FROM roles r, acl_permissions ap WHERE r.r_key = ? AND ap.ap_key = 'settings.manage'")->execute([self::$roleKey]);
        self::$db->prepare('INSERT INTO subscriber_roles (sr_s_id, sr_r_id) SELECT ?, r_id FROM roles WHERE r_key = ?')->execute([self::$staffId, self::$roleKey]);
        $staff = self::signIn();
        $page = self::request($staff, 'GET', '/settings');
        self::assertSame(200, $page['status'], 'a custom role with settings.manage');
        self::assertStringContainsString('href="/settings"', $page['body'], 'navigation follows the permission');
        self::assertStringNotContainsString('href="/roles"', $page['body']);

        $admin = self::client();
        self::loginAsAdmin($admin);
        $body = self::request($admin, 'GET', '/settings')['body'];
        foreach (['Site identity', 'Contact details', 'Mail sender', 'Transactional mail', 'Subscription messages', 'Archives', 'Sign-in'] as $section) {
            self::assertStringContainsString('>' . $section . '</h2>', $body);
        }
        self::assertDoesNotMatchRegularExpression('/name="(DB_|GDB_|APP_SECRET|APP_SETTINGS_KEY|APP_ENV|APP_DEBUG|APP_BASE_URL|TRUSTED_PROXIES|SUPPRESSION_PROVIDER|CONTACT_LOG_FILE|APP_LOG_DIR|CATTOMAIL_)/', $body, 'infrastructure stays in .env');
        self::assertStringNotContainsString('MAIL_SMTP_SERVERS_JSON', $body, 'campaign content goes to catto-mail, not SMTP failover servers');
    }

    public function testOverrideBadgesAndReset(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $name = 'Overridden ' . bin2hex(random_bytes(3));

        $saved = self::save($admin, 'site', ['[APP_LIST_NAME]' => $name]);
        self::assertStringContainsString('Site identity settings saved.', $saved);
        self::assertMatchesRegularExpression('#for="settings_site_APP_LIST_NAME">List name</label><span class="badge text-bg-primary"[^>]*>Database</span>#', $saved);
        self::assertMatchesRegularExpression('#for="settings_site_APP_ORGANISATION">Organisation</label><span class="badge text-bg-light border">\.env</span>#', $saved);
        self::assertMatchesRegularExpression('#<span class="badge text-bg-secondary">Default</span>#', $saved, 'settings .env leaves unset');
        self::assertStringContainsString('</i>' . $name . '</a>', self::request(self::client(), 'GET', '/')['body'], 'applies immediately everywhere');
        self::assertStringContainsString('no changes to save', self::save($admin, 'site', []));

        self::assertStringContainsString('List name now comes from .env.', self::post($admin, '/settings/reset/APP_LIST_NAME'));
        self::assertStringNotContainsString($name, self::request(self::client(), 'GET', '/')['body']);
    }

    public function testInvalidInputIsShownAtItsFieldAndNothingIsSaved(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);

        $response = self::submitForm($admin, '/settings', 'settings_contact', [
            'settings_contact[APP_STREET_ADDRESS]' => '12 Kept Street',
            'settings_contact[APP_FACEBOOK_URL]' => 'javascript:alert(1)',
        ]);
        self::assertSame(422, $response['status']);
        $page = $response['body'];
        self::assertCleanPage('/settings/contact', $page);
        self::assertMatchesRegularExpression('#<input type="url"\s+id="settings_contact_APP_FACEBOOK_URL"[^>]*class="form-control is-invalid" aria-invalid="true" aria-describedby="[^"]*settings_contact_APP_FACEBOOK_URL_error1"[^>]*value="javascript:alert\(1\)"#', $page, 'the error is at the field, linked for screen readers, with the value kept');
        self::assertMatchesRegularExpression('#id="settings_contact_APP_FACEBOOK_URL_error1">Enter a full web address#', $page);
        self::assertStringContainsString('value="12 Kept Street"', $page, 'the other values are kept');
        self::assertStringContainsString('class="nav-link active text-danger" id="tab-contact"', $page, 'the tab with errors is shown');
        self::assertStringContainsString('nothing was saved', $page);
        self::assertSame('0', (string) self::value("SELECT COUNT(*) FROM options WHERE o_key LIKE 'setting:%'"), 'nothing stored');

        $number = self::submitForm($admin, '/settings', 'settings_signin', ['settings_signin[AUTH_MAGIC_LINK_TTL]' => 'soon']);
        self::assertSame(422, $number['status']);
        self::assertMatchesRegularExpression('#id="settings_signin_AUTH_MAGIC_LINK_TTL_error1">Enter a whole number#', $number['body']);
        $range = self::submitForm($admin, '/settings', 'settings_signin', ['settings_signin[AUTH_MAGIC_LINK_TTL]' => '10']);
        self::assertMatchesRegularExpression('#id="settings_signin_AUTH_MAGIC_LINK_TTL_error1">Enter a whole number of at least 60#', $range['body']);

        $forged = self::submitForm($admin, '/settings', 'settings_site', ['settings_site[csrf]' => 'forged', 'settings_site[APP_LIST_NAME]' => 'Forged']);
        self::assertSame(422, $forged['status'], 'a bad CSRF token is rejected');
        self::assertStringContainsString('CSRF token is invalid', $forged['body']);
        self::assertSame('0', (string) self::value("SELECT COUNT(*) FROM options WHERE o_key LIKE 'setting:%'"));
    }

    public function testSecretsNeverReachThePage(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $password = 'Pw-' . bin2hex(random_bytes(6));
        $dsn = ['[MAILER_DSN][host]' => 'smtp.example.invalid', '[MAILER_DSN][port]' => '587', '[MAILER_DSN][security]' => 'smtp', '[MAILER_DSN][username]' => 'user'];

        $page = self::save($admin, 'smtp', $dsn + ['[MAILER_DSN][password]' => $password]);
        self::assertStringContainsString('Transactional mail settings saved.', $page, (string) (preg_match('#<div class="alert[^>]*>(.*?)</div>#s', $page, $alert) ? trim(strip_tags($alert[1])) : 'no alert'));
        self::assertStringContainsString('value="smtp.example.invalid"', $page);
        self::assertStringContainsString('placeholder="Leave empty to keep the current password"', $page);
        foreach ([$password, rawurlencode($password)] as $secret) {
            self::assertStringNotContainsString($secret, $page, 'never in the page source');
        }
        $stored = self::storedSecrets();
        self::assertStringNotContainsString($password, $stored, 'nor stored as plaintext');
        self::assertSame(1, substr_count($stored, 'enc:v1:'));

        self::assertStringContainsString('no changes to save', self::save($admin, 'smtp', []), 'empty passwords keep the stored ones');

        // A replacement password is saved only with a valid form; the page never shows it.
        $failed = self::submitForm($admin, '/settings', 'settings_smtp', [
            'settings_smtp[MAILER_DSN][password]' => 'Replacement-' . $password,
            'settings_smtp[MAILER_DSN][port]' => '70000',
        ]);
        self::assertSame(422, $failed['status']);
        self::assertMatchesRegularExpression('#id="settings_smtp_MAILER_DSN_port_error1">The port must be between 1 and 65535#', $failed['body']);
        self::assertStringNotContainsString('Replacement-' . $password, $failed['body'], 'a rejected password is not redisplayed');
        self::assertStringContainsString('value="smtp.example.invalid"', $failed['body'], 'the other values are kept');
        self::assertSame($stored, self::storedSecrets(), 'the stored secrets are unchanged');

        self::assertStringContainsString('every setting now comes from .env or its default', self::post($admin, '/settings/smtp/reset'));
        self::assertSame('', self::storedSecrets());
    }

    private static function signIn(): \CurlHandle
    {
        $client = self::client();
        self::assertSame(302, self::signInWithLink($client, self::issueLoginToken(self::$staffId))['status']);
        return $client;
    }

    private static function uuid(): string
    {
        return (string) self::value('SELECT s_uuid FROM subscribers WHERE s_id = ' . self::$staffId);
    }

    /**
     * Submit a Settings tab as the page shows it, with $changes keyed by the
     * field path inside the form (e.g. "[APP_LIST_NAME]"); returns the page
     * after the redirect.
     *
     * @param array<string, string> $changes
     */
    private static function save(\CurlHandle $client, string $group, array $changes): string
    {
        $overrides = [];
        foreach ($changes as $path => $value) {
            $overrides['settings_' . $group . $path] = $value;
        }
        $response = self::submitForm($client, '/settings', 'settings_' . $group, $overrides);
        self::assertSame(302, $response['status'], "save {$group}: " . (preg_match_all('#class="invalid-feedback[^>]*>([^<]+)#', $response['body'], $m) ? implode('; ', $m[1]) : ''));
        $page = self::request($client, 'GET', '/settings')['body'];
        self::assertCleanPage('/settings', $page);
        return $page;
    }

    /** POST a reset button (plain csrf field) and return the page after the redirect. */
    private static function post(\CurlHandle $client, string $path): string
    {
        $form = self::request($client, 'GET', '/settings')['body'];
        $csrf = self::match('/name="csrf" value="([^"]+)"/', $form);
        self::assertSame(302, self::request($client, 'POST', $path, ['csrf' => html_entity_decode($csrf[1])])['status'], "POST {$path}");
        $page = self::request($client, 'GET', '/settings')['body'];
        self::assertCleanPage($path, $page);
        return $page;
    }

    private static function storedSecrets(): string
    {
        return (string) self::value("SELECT string_agg(o_value, ' ' ORDER BY o_key) FROM options WHERE o_key = 'setting:MAILER_DSN'");
    }
}
