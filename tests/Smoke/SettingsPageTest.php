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
        $this->saved = self::$db->query("SELECT * FROM options WHERE o_key LIKE 'setting:%'")->fetchAll(\PDO::FETCH_ASSOC);
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
        foreach (['Site identity', 'Contact details', 'Mail sender', 'SMTP servers', 'Subscription messages', 'Archives', 'Sign-in'] as $section) {
            self::assertStringContainsString('>' . $section . '</h2>', $body);
        }
        self::assertDoesNotMatchRegularExpression('/name="(DB_|GDB_|APP_SECRET|APP_SETTINGS_KEY|APP_ENV|APP_DEBUG|APP_BASE_URL|TRUSTED_PROXIES|SUPPRESSION_PROVIDER|CONTACT_LOG_FILE|APP_LOG_DIR)/', $body, 'infrastructure stays in .env');
    }

    public function testOverrideBadgesAndReset(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $name = 'Overridden ' . bin2hex(random_bytes(3));

        $saved = self::post($admin, '/settings/site', ['APP_LIST_NAME' => $name, 'APP_ORGANISATION' => self::shown($admin, 'APP_ORGANISATION'), 'APP_ABOUT_US' => '']);
        self::assertStringContainsString('Site identity settings saved.', $saved);
        self::assertMatchesRegularExpression('#for="setting-app_list_name">List name</label>\s*<span class="badge text-bg-primary"[^>]*>Database</span>#', $saved);
        self::assertMatchesRegularExpression('#for="setting-app_organisation">Organisation</label>\s*<span class="badge text-bg-light border">\.env</span>#', $saved);
        self::assertMatchesRegularExpression('#<span class="badge text-bg-secondary">Default</span>#', $saved, 'settings .env leaves unset');
        self::assertStringContainsString('</i>' . $name . '</a>', self::request(self::client(), 'GET', '/')['body'], 'applies immediately everywhere');
        self::assertStringContainsString('no changes to save', self::post($admin, '/settings/site', ['APP_LIST_NAME' => $name, 'APP_ORGANISATION' => self::shown($admin, 'APP_ORGANISATION'), 'APP_ABOUT_US' => '']));

        self::assertStringContainsString('List name now comes from .env.', self::post($admin, '/settings/reset/APP_LIST_NAME', []));
        self::assertStringNotContainsString($name, self::request(self::client(), 'GET', '/')['body']);

        self::assertStringContainsString('must be a whole number', self::post($admin, '/settings/signin', ['AUTH_MAGIC_LINK_TTL' => 'soon']));
    }

    public function testSecretsNeverReachThePage(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $password = 'Pw-' . bin2hex(random_bytes(6));
        $relayPassword = 'Relay-' . bin2hex(random_bytes(6));
        $smtp = ['smtp_scheme' => 'smtp', 'smtp_host' => 'smtp.example.invalid', 'smtp_port' => '587', 'smtp_username' => 'user', 'smtp_options' => '',
            'MAIL_RATE_PER_MINUTE' => '13', 'MAIL_BATCH_SIZE' => '600', 'MAIL_BATCH_DELAY' => '0', 'MAIL_BOUNCE_LIMIT' => '2'];

        $page = self::post($admin, '/settings/smtp', ['smtp_password' => $password, 'servers' => [
            ['index' => '', 'position' => '1', 'active' => '1', 'host' => 'relay.example.invalid', 'port' => '587', 'security' => 'tls', 'username' => 'r', 'password' => $relayPassword, 'batchsize' => '600', 'delay' => '10', 'sendrate' => ''],
        ]] + $smtp);
        self::assertStringContainsString('SMTP servers settings saved.', $page, (string) (preg_match('#<div class="alert[^>]*>(.*?)<button#s', $page, $alert) ? trim(strip_tags($alert[1])) : 'no alert'));
        self::assertStringContainsString('value="smtp.example.invalid"', $page);
        self::assertStringContainsString('value="relay.example.invalid"', $page);
        self::assertStringContainsString('placeholder="Leave empty to keep the current password"', $page);
        foreach ([$password, $relayPassword, rawurlencode($password)] as $secret) {
            self::assertStringNotContainsString($secret, $page, 'never in the page source');
        }
        $stored = (string) self::$db->query("SELECT string_agg(o_value, ' ') FROM options WHERE o_key IN ('setting:MAILER_DSN', 'setting:MAIL_SMTP_SERVERS_JSON')")->fetchColumn();
        self::assertStringNotContainsString($password, $stored, 'nor stored as plaintext');
        self::assertStringNotContainsString($relayPassword, $stored);
        self::assertSame(2, substr_count($stored, 'enc:v1:'));

        $kept = self::post($admin, '/settings/smtp', ['smtp_password' => '', 'servers' => [
            ['index' => '0', 'position' => '1', 'active' => '1', 'host' => 'relay.example.invalid', 'port' => '587', 'security' => 'tls', 'username' => 'r', 'password' => '', 'batchsize' => '600', 'delay' => '10', 'sendrate' => ''],
        ]] + $smtp);
        self::assertStringContainsString('no changes to save', $kept, 'empty passwords keep the stored ones');

        self::assertStringContainsString('every setting now comes from .env or its default', self::post($admin, '/settings/smtp/reset', []));
        self::assertSame('0', (string) self::$db->query("SELECT COUNT(*) FROM options WHERE o_key IN ('setting:MAILER_DSN', 'setting:MAIL_SMTP_SERVERS_JSON')")->fetchColumn());
    }

    private static function signIn(): \CurlHandle
    {
        $client = self::client();
        self::assertSame(302, self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken(self::$staffId))['status']);
        return $client;
    }

    private static function uuid(): string
    {
        return (string) self::$db->query('SELECT s_uuid FROM subscribers WHERE s_id = ' . self::$staffId)->fetchColumn();
    }

    /** The value the page currently shows for a text setting. */
    private static function shown(\CurlHandle $client, string $name): string
    {
        $page = self::request($client, 'GET', '/settings')['body'];
        return preg_match('#name="' . $name . '"[^>]*value="([^"]*)"#', $page, $m) === 1 ? html_entity_decode($m[1]) : '';
    }

    /** @param array<string, mixed> $fields */
    private static function post(\CurlHandle $client, string $path, array $fields): string
    {
        $form = self::request($client, 'GET', '/settings')['body'];
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $form, $csrf));
        $response = self::request($client, 'POST', $path, ['csrf' => html_entity_decode($csrf[1])] + self::flatten($fields));
        self::assertSame(302, $response['status'], "POST {$path}");
        $page = self::request($client, 'GET', '/settings')['body'];
        self::assertCleanPage($path, $page);
        return $page;
    }

    /**
     * Nested form fields (servers[0][host]) as flat POST names.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private static function flatten(array $fields, string $prefix = ''): array
    {
        $flat = [];
        foreach ($fields as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            $flat += is_array($value) ? self::flatten($value, $name) : [$name => (string) $value];
        }
        return $flat;
    }
}
