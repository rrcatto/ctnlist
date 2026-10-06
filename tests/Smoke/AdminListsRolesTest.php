<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * List and role administration (/lists, /roles): creation, validation,
 * deletion, permissions, assignment, CSRF and ACL enforcement.
 */
final class AdminListsRolesTest extends SmokeTestCase
{
    /**
     * Subscribers cannot be deleted once they have consent events (the
     * events table is append-only), so the editor is a persistent fixture.
     */
    private const EDITOR_EMAIL = 'smoke-editor@ctnlist.test';

    private static string $suffix;
    private static int $editorId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$suffix = strtoupper(bin2hex(random_bytes(2)));
        self::$db->prepare('INSERT INTO subscribers (s_email) VALUES (?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')
            ->execute([self::EDITOR_EMAIL]);
        self::$editorId = (int) self::scalar('SELECT s_id FROM subscribers WHERE s_email = ?', [self::EDITOR_EMAIL]);
    }

    public static function tearDownAfterClass(): void
    {
        $roleKey = 'smoke-%-' . strtolower(self::$suffix);
        self::$db->prepare('DELETE FROM subscriber_roles WHERE sr_r_id IN (SELECT r_id FROM roles WHERE r_key LIKE ?)')->execute([$roleKey]);
        self::$db->prepare('DELETE FROM roles WHERE r_key LIKE ?')->execute([$roleKey]);
        self::$db->prepare('DELETE FROM lists WHERE l_name LIKE ?')->execute(['Smoke % ' . self::$suffix]);
        self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')->execute([self::$editorId, self::$startedAt]);
        self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')->execute([self::$editorId, self::$startedAt]);
        parent::tearDownAfterClass();
    }

    public function testListLifecycle(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $shortcode = 'S' . self::$suffix;
        $name = 'Smoke list ' . self::$suffix;

        $page = self::submitAndFollow($client, '/lists', 'list', ['list[shortcode]' => strtolower($shortcode), 'list[name]' => $name, 'list[description]' => 'd']);
        self::assertStringContainsString('List ' . $shortcode . ' created with ID', $page);
        self::assertStringContainsString('<code>' . $shortcode . '</code>', $page, 'shortcode stored in upper case');

        $invalid = self::submitForm($client, '/lists', 'list', ['list[shortcode]' => 'ab', 'list[name]' => 'Kept name', 'list[description]' => 'Kept description']);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="list_shortcode_error1">Use 3 to 6 uppercase letters or numbers#', $invalid['body']);
        self::assertStringContainsString('value="Kept name"', $invalid['body'], 'values are kept');
        self::assertStringContainsString('>Kept description</textarea>', $invalid['body']);
        $taken = self::submitForm($client, '/lists', 'list', ['list[shortcode]' => $shortcode, 'list[name]' => 'Smoke other ' . self::$suffix]);
        self::assertMatchesRegularExpression('#id="list_shortcode_error1">A list with that shortcode already exists#', $taken['body']);
        $takenName = self::submitForm($client, '/lists', 'list', ['list[shortcode]' => 'X' . self::$suffix, 'list[name]' => $name]);
        self::assertMatchesRegularExpression('#id="list_name_error1">A list with that name already exists#', $takenName['body']);

        $id = self::$db->prepare('SELECT l_id FROM lists WHERE l_shortcode = ?');
        $id->execute([$shortcode]);
        $listId = (string) $id->fetchColumn();
        $edited = self::submitAndFollow($client, '/lists/' . $listId . '/edit', 'list', ['list[name]' => $name . ' renamed', 'list[description]' => 'New description']);
        self::assertStringContainsString('List ' . $shortcode . ' saved.', $edited);
        self::assertStringContainsString('New description', $edited);
        $blank = self::submitForm($client, '/lists/' . $listId . '/edit', 'list', ['list[name]' => '']);
        self::assertSame(422, $blank['status']);
        self::assertMatchesRegularExpression('#id="list_name_error1">Enter a name#', $blank['body']);

        $allId = (int) self::value("SELECT l_id FROM lists WHERE l_shortcode = 'ALL'");
        self::assertStringContainsString('System lists cannot be deleted.', self::post($client, '/lists/delete', ['list_id' => (string) $allId]));
        $system = self::request($client, 'GET', '/lists/' . $allId . '/edit');
        self::assertSame(302, $system['status'], 'system lists cannot be edited');
        self::assertStringContainsString('System lists cannot be changed.', self::request($client, 'GET', '/lists')['body']);
        self::assertStringContainsString('List deleted.', self::post($client, '/lists/delete', ['list_id' => $listId]));
        $id->execute([$shortcode]);
        self::assertFalse($id->fetchColumn(), 'list row removed');

        $missing = self::request($client, 'POST', '/lists/delete', ['list_id' => '999999', 'csrf' => self::csrf($client, '/lists')]);
        self::assertSame(404, $missing['status']);
    }

    public function testStateChangesRequireCsrf(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        foreach (['/lists/delete', '/roles/assign', '/roles/unassign'] as $path) {
            self::assertSame(403, self::request($client, 'POST', $path, ['csrf' => 'forged'])['status'], $path);
        }
        // Symfony forms reject a forged token as a form error and change nothing.
        foreach ([['/lists', 'list', ['list[shortcode]' => 'FORGE', 'list[name]' => 'Forged ' . self::$suffix]], ['/roles', 'role', ['role[key]' => 'forged', 'role[name]' => 'Forged ' . self::$suffix]]] as [$page, $form, $fields]) {
            $response = self::submitForm($client, $page, $form, [$form . '[csrf]' => 'forged'] + $fields);
            self::assertSame(422, $response['status'], $page);
            self::assertStringContainsString('CSRF token is invalid', $response['body']);
        }
        self::assertSame('0', self::scalar("SELECT COUNT(*) FROM lists WHERE l_name = ?", ['Forged ' . self::$suffix]));
        self::assertSame('0', self::scalar("SELECT COUNT(*) FROM roles WHERE r_name = ?", ['Forged ' . self::$suffix]));
    }

    public function testRolesPermissionsAndAssignment(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $key = 'smoke-editor-' . strtolower(self::$suffix);

        self::assertStringContainsString('Role created.', self::submitAndFollow($admin, '/roles', 'role', ['role[key]' => $key, 'role[name]' => 'Smoke editor ' . self::$suffix]));
        $taken = self::submitForm($admin, '/roles', 'role', ['role[key]' => $key, 'role[name]' => 'Smoke editor 2 ' . self::$suffix]);
        self::assertSame(422, $taken['status']);
        self::assertMatchesRegularExpression('#id="role_key_error1">A role with that key already exists#', $taken['body']);
        self::assertStringContainsString('value="Smoke editor 2 ' . self::$suffix . '"', $taken['body'], 'values are kept');
        $bad = self::submitForm($admin, '/roles', 'role', ['role[key]' => 'Bad Key', 'role[name]' => '']);
        self::assertMatchesRegularExpression('#id="role_key_error1">Use up to 64 lowercase letters#', $bad['body']);
        self::assertMatchesRegularExpression('#id="role_name_error1">Enter a name#', $bad['body']);

        $roleId = self::scalar('SELECT r_id FROM roles WHERE r_key = ?', [$key]);
        $permissionId = self::scalar("SELECT ap_id FROM acl_permissions WHERE ap_key = 'lists.manage'");
        $page = self::submitAndFollow($admin, '/roles/' . $roleId, 'role_permissions', ['role_permissions[permissions][]' => [$permissionId]]);
        self::assertStringContainsString('Permissions updated.', $page);
        self::assertSame('1', self::scalar('SELECT COUNT(*) FROM role_permissions WHERE rp_r_id = ?', [$roleId]));

        $systemRoleId = self::scalar("SELECT r_id FROM roles WHERE r_key = 'administrator'");
        $system = self::request($admin, 'POST', '/roles/' . $systemRoleId . '/permissions', self::formFields(self::request($admin, 'GET', '/roles/' . $roleId)['body'], 'role_permissions'));
        self::assertSame(422, $system['status'], 'system-role permissions are fixed');

        $subscriberId = (string) self::$editorId;
        self::assertStringContainsString('Role assigned.', self::post($admin, '/roles/assign', ['subscriber_id' => $subscriberId, 'role_key' => $key], '/roles'));
        self::assertSame(
            (string) self::$admin['s_id'],
            self::scalar('SELECT sr_assigned_by_s_id FROM subscriber_roles WHERE sr_s_id = ? AND sr_r_id = ?', [$subscriberId, $roleId]),
            'assignment records the administrator'
        );
        self::assertStringContainsString('Unknown subscriber or role.', self::post($admin, '/roles/assign', ['subscriber_id' => $subscriberId, 'role_key' => 'no-such-role'], '/roles'));

        // The editor holds lists.manage but not roles.manage.
        $editor = self::client();
        self::request($editor, 'GET', '/auth/verify?token=' . self::issueLoginToken((int) $subscriberId));
        self::assertSame(200, self::request($editor, 'GET', '/lists')['status']);
        self::assertSame(403, self::request($editor, 'GET', '/roles')['status']);
        $nav = self::request($editor, 'GET', '/lists')['body'];
        self::assertStringContainsString('href="/lists"', $nav, 'admin bar shows the granted tool');
        self::assertStringNotContainsString('href="/roles"', $nav, 'and hides the others');
    }

    /**
     * POST a form with a fresh CSRF token, follow the redirect and return the page.
     *
     * @param array<string, string> $fields
     */
    private static function post(\CurlHandle $client, string $path, array $fields, string $formPage = '/lists'): string
    {
        $response = self::request($client, 'POST', $path, $fields + ['csrf' => self::csrf($client, $formPage)]);
        self::assertSame(302, $response['status'], "POST {$path}");
        $page = self::request($client, 'GET', (string) parse_url($response['location'], PHP_URL_PATH));
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

    /** @param list<string> $params */
    private static function scalar(string $sql, array $params = []): string
    {
        $statement = self::$db->prepare($sql);
        $statement->execute($params);
        return (string) $statement->fetchColumn();
    }
}
