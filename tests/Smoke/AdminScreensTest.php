<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Presentation of the administration screens: page headers, empty states,
 * confirmation of destructive actions, pagination markup, and actions shown
 * only to users holding the permission they need.
 */
final class AdminScreensTest extends SmokeTestCase
{
    /** Persistent fixture subscriber for the custom-role checks. */
    private const STAFF_EMAIL = 'smoke-staff@ctnlist.test';

    private static int $staffId;
    private static string $roleKey;
    private static string $muid;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->prepare('INSERT INTO subscribers (s_email) VALUES (?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute([self::STAFF_EMAIL]);
        $id = self::$db->prepare('SELECT s_id FROM subscribers WHERE s_email = ?');
        $id->execute([self::STAFF_EMAIL]);
        self::$staffId = (int) $id->fetchColumn();
        self::$roleKey = 'smoke-staff-' . bin2hex(random_bytes(3));
        self::$muid = bin2hex(random_bytes(16));
        self::$db->prepare("INSERT INTO messages (m_uniqid, m_subject, m_html, m_text) VALUES (?, 'Screens fixture', '<p>x</p>', 'x')")->execute([self::$muid]);
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
        self::$db->prepare('DELETE FROM messages WHERE m_uniqid = ?')->execute([self::$muid]);
        self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')->execute([self::$staffId, self::$startedAt]);
        self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')->execute([self::$staffId, self::$startedAt]);
        parent::tearDownAfterClass();
    }

    public function testAdministratorScreens(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);

        $messages = self::request($client, 'GET', '/messages')['body'];
        self::assertMatchesRegularExpression('#<h1 class="h3 mb-1">Messages</h1>#', $messages, 'page header');
        self::assertStringContainsString('href="/message"', $messages, 'primary action');
        foreach (['/sendtome/', '/queuelist/', '/processqueue/'] as $action) {
            self::assertStringContainsString('href="' . $action . self::$muid . '"', $messages, $action);
        }

        $queue = self::request($client, 'GET', '/queue')['body'];
        if (str_contains($queue, 'There is nothing in the queue.')) {
            self::assertStringContainsString('border-dashed', $queue, 'empty state');
        } else {
            self::assertStringContainsString('data-confirm="Clear the entire queue?"', $queue);
        }

        self::assertMatchesRegularExpression('#action="/lists/delete" method="post" data-confirm=#', self::listFixture($client));
        self::assertStringContainsString('data-confirm=', self::request($client, 'GET', '/bulk-unsubscribe')['body'], 'bulk unsubscribe asks first');

        $roles = self::request($client, 'GET', '/roles')['body'];
        self::assertStringContainsString('Permissions by role', $roles);
        self::assertStringNotContainsString('<fieldset disabled>', $roles, 'administrators may edit permissions');
    }

    public function testPaginationMarkup(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $body = self::request($client, 'GET', '/sendlog/2?r=1')['body'];

        self::assertStringContainsString('<nav aria-label="Pagination">', $body);
        self::assertMatchesRegularExpression('#<span class="page-link" aria-current="page">2</span>#', $body);
        self::assertMatchesRegularExpression('#href="/sendlog/1\?r=1" aria-label="Previous page"#', $body, 'filters kept');
        self::assertMatchesRegularExpression('#href="/sendlog/3\?r=1" aria-label="Next page"#', $body);
        self::assertMatchesRegularExpression('#Page 2 of \d+#', $body);
    }

    /** A role with messages.manage and subscribers.view only sees the actions it may use. */
    public function testActionsFollowPermissions(): void
    {
        $client = self::signInWith(['messages.manage', 'subscribers.view', 'roles.manage']);

        $messages = self::request($client, 'GET', '/messages')['body'];
        self::assertStringContainsString('href="/sendtome/' . self::$muid . '"', $messages, 'proof needs messages.manage');
        self::assertStringNotContainsString('href="/queuelist/' . self::$muid . '"', $messages, 'queue needs messages.queue');
        self::assertStringNotContainsString('href="/processqueue/' . self::$muid . '"', $messages, 'sending needs queue.process');
        self::assertStringNotContainsString('href="/message-views/', $messages, 'activity needs logs.view');
        self::assertSame(403, self::request($client, 'GET', '/queuelist/' . self::$muid)['status'], 'still enforced');

        $subscribers = self::request($client, 'GET', '/subscribers')['body'];
        self::assertStringNotContainsString('href="/bulk-subscribe"', $subscribers, 'bulk actions need subscribers.manage');
        self::assertStringNotContainsString('href="/subscribe/', $subscribers, 'editing needs subscribers.manage');
        self::assertStringContainsString(self::$admin['s_email'], $subscribers);

        $roleId = (int) self::value("SELECT r_id FROM roles WHERE r_key = '" . self::$roleKey . "'");
        $role = self::request($client, 'GET', '/roles/' . $roleId)['body'];
        self::assertStringContainsString('Read only: changing them needs <code>acl.manage</code>', $role);
        self::assertMatchesRegularExpression('#name="role_permissions\[permissions\]\[\]" disabled="disabled"#', $role);
        self::assertStringNotContainsString('Save permissions', $role);
    }

    /** The lists page with one deletable list on it. */
    private static function listFixture(\CurlHandle $client): string
    {
        $shortcode = 'SC' . strtoupper(bin2hex(random_bytes(2)));
        self::$db->prepare('INSERT INTO lists (l_shortcode, l_name) VALUES (?, ?)')->execute([$shortcode, 'Screens ' . $shortcode]);
        try {
            return self::request($client, 'GET', '/lists')['body'];
        } finally {
            self::$db->prepare('DELETE FROM lists WHERE l_shortcode = ?')->execute([$shortcode]);
        }
    }

    /** @param list<string> $permissions */
    private static function signInWith(array $permissions): \CurlHandle
    {
        self::$db->prepare('INSERT INTO roles (r_key, r_name) VALUES (?, ?)')->execute([self::$roleKey, 'Smoke staff ' . self::$roleKey]);
        $grant = self::$db->prepare('INSERT INTO role_permissions (rp_r_id, rp_ap_id) SELECT r.r_id, ap.ap_id FROM roles r JOIN acl_permissions ap ON ap.ap_key = ? WHERE r.r_key = ?');
        foreach ($permissions as $permission) {
            $grant->execute([$permission, self::$roleKey]);
        }
        self::$db->prepare('INSERT INTO subscriber_roles (sr_s_id, sr_r_id) SELECT ?, r_id FROM roles WHERE r_key = ?')->execute([self::$staffId, self::$roleKey]);

        $client = self::client();
        $response = self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken(self::$staffId));
        self::assertSame(302, $response['status']);
        return $client;
    }
}
