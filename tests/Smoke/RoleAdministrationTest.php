<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Roles & ACL through the pages: the custom-role lifecycle as administrator,
 * roles.manage without acl.manage, both permissions, and proofs to any
 * address.
 */
final class RoleAdministrationTest extends SmokeTestCase
{
    /** Persistent fixtures (subscribers with consent events cannot be deleted). */
    private const STAFF_EMAIL = 'smoke-roles@ctnlist.test';
    private const MEMBER_EMAIL = 'smoke-member@ctnlist.test';

    private static int $staffId;
    private static int $memberId;
    private static string $suffix;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $insert = self::$db->prepare('INSERT INTO subscribers (s_email, s_fname, s_lname) VALUES (?, ?, ?) ON CONFLICT ((LOWER(s_email))) DO NOTHING');
        $insert->execute([self::STAFF_EMAIL, 'Role', 'Staff']);
        $insert->execute([self::MEMBER_EMAIL, 'Member', 'Fixture']);
        self::$staffId = self::id(self::STAFF_EMAIL);
        self::$memberId = self::id(self::MEMBER_EMAIL);
        self::$suffix = bin2hex(random_bytes(3));
    }

    public static function tearDownAfterClass(): void
    {
        $roles = 'smoke-r%-' . self::$suffix;
        self::$db->prepare('DELETE FROM subscriber_roles WHERE sr_r_id IN (SELECT r_id FROM roles WHERE r_key LIKE ?)')->execute([$roles]);
        self::$db->prepare('DELETE FROM roles WHERE r_key LIKE ?')->execute([$roles]);
        foreach ([self::$staffId, self::$memberId] as $id) {
            self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')->execute([$id, self::$startedAt]);
            self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')->execute([$id, self::$startedAt]);
        }
        parent::tearDownAfterClass();
    }

    public function testCustomRoleLifecycleAsAdministrator(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $key = 'smoke-rlife-' . self::$suffix;

        self::assertStringContainsString('Role created.', self::submitAndFollow($admin, '/roles', 'role', ['role[key]' => strtoupper($key), 'role[name]' => 'Life ' . self::$suffix, 'role[description]' => 'd']));
        $roleId = self::roleId($key);
        $page = self::request($admin, 'GET', '/roles/' . $roleId)['body'];
        self::assertStringContainsString('Nobody holds this role.', $page);
        self::assertStringContainsString('Delete this role', $page);

        $renamed = self::submitAndFollow($admin, '/roles/' . $roleId, 'role', ['role[name]' => 'Renamed ' . self::$suffix, 'role[description]' => 'New text']);
        self::assertStringContainsString('Role updated.', $renamed);
        self::assertStringContainsString('Renamed ' . self::$suffix, $renamed);
        self::assertSame($key, self::scalar('SELECT r_key FROM roles WHERE r_id = ?', [$roleId]), 'key stored in lower case and unchanged');
        $blank = self::submitForm($admin, '/roles/' . $roleId, 'role', ['role[name]' => ' ', 'role[description]' => 'Kept text']);
        self::assertSame(422, $blank['status']);
        self::assertMatchesRegularExpression('#id="role_name_error1">Enter a name#', $blank['body']);
        self::assertStringContainsString('value="Kept text"', $blank['body']);
        $duplicate = self::submitForm($admin, '/roles/' . $roleId, 'role', ['role[name]' => 'Administrator']);
        self::assertMatchesRegularExpression('#id="role_name_error1">A role with that name already exists#', $duplicate['body']);

        $logs = self::scalar("SELECT ap_id FROM acl_permissions WHERE ap_key = 'logs.view'");
        self::assertStringContainsString('Permissions updated.', self::submitAndFollow($admin, '/roles/' . $roleId, 'role_permissions', ['role_permissions[permissions][]' => [$logs]]));

        $search = self::request($admin, 'GET', '/roles?q=smoke-member')['body'];
        self::assertStringContainsString(self::MEMBER_EMAIL, $search, 'search finds the subscriber');
        self::assertMatchesRegularExpression('#<span class="badge theme-inverse me-1">Subscriber</span>#', $search, 'current roles shown');
        self::assertStringContainsString('Role assigned.', self::post($admin, '/roles/assign', ['subscriber_id' => (string) self::$memberId, 'role_key' => $key, 'back' => '/roles?q=smoke-member'], '/roles'));
        self::assertStringContainsString('already holds', self::post($admin, '/roles/assign', ['subscriber_id' => (string) self::$memberId, 'role_key' => $key], '/roles'));
        foreach (['https://evil.example/roles', '//evil.example/roles', '/roles/../logout', "/roles\r\nX: y", '/rolesevil'] as $back) {
            $response = self::request($admin, 'POST', '/roles/assign', ['subscriber_id' => (string) self::$memberId, 'role_key' => $key, 'back' => $back, 'csrf' => self::csrf($admin, '/roles')]);
            self::assertSame(302, $response['status']);
            self::assertSame('/roles', parse_url($response['location'], PHP_URL_PATH), 'back only to the roles pages: ' . json_encode($back) . ' -> ' . $response['location']);
            self::assertContains(parse_url($response['location'], PHP_URL_HOST), [null, 'web'], 'same host');
        }

        $members = self::request($admin, 'GET', '/roles/' . $roleId)['body'];
        self::assertStringContainsString(self::MEMBER_EMAIL, $members, 'member listed');
        self::assertStringContainsString('action="/roles/unassign"', $members);

        self::assertStringContainsString('Role removed from the subscriber.', self::post($admin, '/roles/unassign', ['subscriber_id' => (string) self::$memberId, 'role_id' => (string) $roleId, 'back' => '/roles/' . $roleId], '/roles/' . $roleId));
        self::assertSame('1', self::scalar("SELECT COUNT(*) FROM subscriber_roles sr JOIN roles r ON r.r_id = sr.sr_r_id WHERE sr.sr_s_id = ? AND r.r_key = 'subscriber'", [self::$memberId]), 'system role kept');

        self::post($admin, '/roles/assign', ['subscriber_id' => (string) self::$memberId, 'role_key' => $key], '/roles');
        self::assertStringContainsString('Role deleted.', self::post($admin, '/roles/' . $roleId . '/delete', [], '/roles/' . $roleId));
        self::assertSame('0', self::scalar('SELECT COUNT(*) FROM subscriber_roles WHERE sr_r_id = ?', [$roleId]));
        self::assertSame('0', self::scalar('SELECT COUNT(*) FROM role_permissions WHERE rp_r_id = ?', [$roleId]));
        self::assertSame(404, self::request($admin, 'GET', '/roles/' . $roleId)['status']);

        $system = self::scalar("SELECT r_id FROM roles WHERE r_key = 'administrator'");
        self::assertStringContainsString('System roles cannot be deleted.', self::post($admin, '/roles/' . $system . '/delete', [], '/roles/' . $system));
        $systemPage = self::request($admin, 'GET', '/roles/' . $system)['body'];
        self::assertStringNotContainsString('name="role[name]"', $systemPage, 'system roles cannot be renamed');
        self::assertStringNotContainsString('Delete this role', $systemPage);
        self::assertStringNotContainsString('action="/roles/unassign"', $systemPage);
    }

    /** roles.manage manages membership; permission grants also need acl.manage. */
    public function testRolesManageWithoutAclManage(): void
    {
        $target = 'smoke-rtarget-' . self::$suffix;
        self::createRole($target, []);
        $staff = self::signInWithRole('smoke-rstaff-' . self::$suffix, ['roles.manage']);
        $targetId = self::roleId($target);

        $page = self::request($staff, 'GET', '/roles/' . $targetId)['body'];
        self::assertMatchesRegularExpression('#name="role_permissions\[permissions\]\[\]" disabled="disabled"#', $page, 'permissions read only');
        self::assertStringNotContainsString('Save permissions', $page);
        self::assertStringContainsString('Save details', $page, 'may still edit the role');

        self::assertSame(403, self::request($staff, 'POST', '/roles/' . $targetId . '/permissions', ['role_permissions[csrf]' => self::csrf($staff, '/roles/' . $targetId)])['status'], 'enforced on the server');
        self::assertStringContainsString('Role assigned.', self::post($staff, '/roles/assign', ['subscriber_id' => (string) self::$memberId, 'role_key' => $target], '/roles'));
        self::assertStringContainsString('cannot assign a role with permissions you do not hold', self::post($staff, '/roles/assign', ['subscriber_id' => (string) self::$memberId, 'role_key' => 'administrator'], '/roles'));
        self::assertStringContainsString('cannot assign a role with permissions you do not hold', self::post($staff, '/roles/assign', ['subscriber_id' => (string) self::$staffId, 'role_key' => 'administrator'], '/roles'), 'not even to themselves');
        self::assertSame('0', self::scalar("SELECT COUNT(*) FROM subscriber_roles sr JOIN roles r ON r.r_id = sr.sr_r_id WHERE sr.sr_s_id = ? AND r.r_key = 'administrator'", [(string) self::$staffId]));
    }

    public function testRolesAndAclManageMayGrantHeldPermissions(): void
    {
        $target = 'smoke-racl-' . self::$suffix;
        self::createRole($target, []);
        $staff = self::signInWithRole('smoke-rboth-' . self::$suffix, ['roles.manage', 'acl.manage', 'logs.view']);
        $targetId = self::roleId($target);

        self::assertStringContainsString('Save permissions', self::request($staff, 'GET', '/roles/' . $targetId)['body']);
        $logs = self::scalar("SELECT ap_id FROM acl_permissions WHERE ap_key = 'logs.view'");
        $manage = self::scalar("SELECT ap_id FROM acl_permissions WHERE ap_key = 'subscribers.manage'");
        self::assertStringContainsString('Permissions updated.', self::submitAndFollow($staff, '/roles/' . $targetId, 'role_permissions', ['role_permissions[permissions][]' => [$logs]]));
        $escalate = self::submitForm($staff, '/roles/' . $targetId, 'role_permissions', ['role_permissions[permissions][]' => [$logs, $manage]]);
        self::assertSame(422, $escalate['status']);
        self::assertStringContainsString('cannot grant permissions you do not hold', $escalate['body']);
        self::assertSame('logs.view', self::scalar('SELECT string_agg(ap.ap_key, \',\') FROM role_permissions rp JOIN acl_permissions ap ON ap.ap_id = rp.rp_ap_id WHERE rp.rp_r_id = ?', [$targetId]));
    }

    public function testProofToAnyAddress(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $muid = bin2hex(random_bytes(16));
        self::$db->prepare("INSERT INTO messages (m_uniqid, m_subject, m_html, m_text) VALUES (?, 'Proof fixture', '<p>Hi {firstname}</p>', 'Hi')")->execute([$muid]);
        try {
            $subscribers = self::scalar('SELECT COUNT(*) FROM subscribers');
            $sent = self::submitForm($admin, '/sendtome/' . $muid, 'proof', ['proof[email]' => 'outside-tester@example.org']);
            self::assertSame(200, $sent['status']);
            $page = $sent['body'];
            // Proofs go through catto-mail, which the development stack does not configure (see the integration tests).
            self::assertStringContainsString('Proof message could not be sent: catto-mail is not configured', $page);
            self::assertSame($subscribers, self::scalar('SELECT COUNT(*) FROM subscribers'), 'no subscriber created');
            self::assertSame('0', self::scalar('SELECT COUNT(*) FROM smlog WHERE sml_muid = ?', [$muid]));

            $invalid = self::submitForm($admin, '/sendtome/' . $muid, 'proof', ['proof[email]' => 'not-an-address']);
            self::assertSame(422, $invalid['status']);
            self::assertMatchesRegularExpression('#id="proof_email_error1">Enter a valid email address#', $invalid['body']);
            self::assertStringContainsString('value="not-an-address"', $invalid['body'], 'the address is kept');
        } finally {
            self::$db->prepare('DELETE FROM messages WHERE m_uniqid = ?')->execute([$muid]);
        }
    }

    /** @param list<string> $permissions */
    private static function createRole(string $key, array $permissions): void
    {
        self::$db->prepare('INSERT INTO roles (r_key, r_name) VALUES (?, ?)')->execute([$key, 'Smoke ' . $key]);
        $grant = self::$db->prepare('INSERT INTO role_permissions (rp_r_id, rp_ap_id) SELECT r.r_id, ap.ap_id FROM roles r JOIN acl_permissions ap ON ap.ap_key = ? WHERE r.r_key = ?');
        foreach ($permissions as $permission) {
            $grant->execute([$permission, $key]);
        }
    }

    /** @param list<string> $permissions */
    private static function signInWithRole(string $key, array $permissions): \CurlHandle
    {
        self::createRole($key, $permissions);
        self::$db->prepare('INSERT INTO subscriber_roles (sr_s_id, sr_r_id) SELECT ?, r_id FROM roles WHERE r_key = ?')->execute([self::$staffId, $key]);
        $client = self::client();
        self::assertSame(302, self::signInWithLink($client, self::issueLoginToken(self::$staffId))['status']);
        return $client;
    }

    /**
     * POST a form with a fresh CSRF token and return the page shown afterwards
     * (following the redirect when $redirects).
     *
     * @param array<string, string> $fields
     */
    private static function post(\CurlHandle $client, string $path, array $fields, string $formPage, bool $redirects = true): string
    {
        $response = self::request($client, 'POST', $path, $fields + ['csrf' => self::csrf($client, $formPage)]);
        if (!$redirects && $response['status'] === 200) {
            return $response['body'];
        }
        self::assertSame(302, $response['status'], "POST {$path}");
        $location = (string) parse_url($response['location'], PHP_URL_PATH);
        $query = parse_url($response['location'], PHP_URL_QUERY);
        $page = self::request($client, 'GET', $location . (is_string($query) ? '?' . $query : ''));
        self::assertCleanPage($path, $page['body']);
        return $page['body'];
    }

    private static function csrf(\CurlHandle $client, string $formPage): string
    {
        $body = self::request($client, 'GET', $formPage)['body'];
        // Any token on the page: manual forms and Symfony forms share the token id.
        $match = self::match('/name="(?:\w+\[)?csrf\]?"[^>]*value="([^"]+)"/', $body, "CSRF field on {$formPage}");
        return html_entity_decode($match[1]);
    }

    private static function roleId(string $key): int
    {
        return (int) self::scalar('SELECT r_id FROM roles WHERE r_key = ?', [$key]);
    }

    private static function id(string $email): int
    {
        return (int) self::scalar('SELECT s_id FROM subscribers WHERE s_email = ?', [$email]);
    }

    /** @param list<int|string> $params */
    private static function scalar(string $sql, array $params = []): string
    {
        $statement = self::$db->prepare($sql);
        $statement->execute($params);
        return (string) $statement->fetchColumn();
    }
}
