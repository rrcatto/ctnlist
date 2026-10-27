<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * The subscriber page's cards (Admin\SubscriberController::subscriberPage()): inviting to a list,
 * the roles a subscriber holds and the delivery block, each its own form and htmx region, outside
 * the profile form.
 */
final class SubscriberPageTest extends SmokeTestCase
{
    private const EMAIL = 'smoke-subscriber-page@ctnlist.test';

    /** @var array{s_id: int, s_uuid: string} */
    private static array $subscriber;
    private static string $suffix;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->prepare('INSERT INTO subscribers (s_email, s_fname) VALUES (?, ?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute([self::EMAIL, 'Page']);
        $row = self::rows('SELECT s_id, s_uuid FROM subscribers WHERE LOWER(s_email) = ?', [self::EMAIL])[0];
        self::$subscriber = ['s_id' => (int) $row['s_id'], 's_uuid' => (string) $row['s_uuid']];
        self::$suffix = bin2hex(random_bytes(3));
    }

    public static function tearDownAfterClass(): void
    {
        $roles = 'smoke-page-%-' . self::$suffix;
        self::$db->prepare('DELETE FROM subscriber_roles WHERE sr_r_id IN (SELECT r_id FROM roles WHERE r_key LIKE ?)')->execute([$roles]);
        self::$db->prepare('DELETE FROM roles WHERE r_key LIKE ?')->execute([$roles]);
        parent::tearDownAfterClass();
    }

    public function testInvitingToAListIsItsOwnFormAndAnswersInPlace(): void
    {
        $code = 'SP' . strtoupper(substr(self::$suffix, 0, 4));
        self::$db->prepare('INSERT INTO lists (l_shortcode, l_name) VALUES (?, ?)')->execute([$code, 'Smoke page list ' . self::$suffix]);
        $listId = (string) self::value('SELECT l_id FROM lists WHERE l_shortcode = ?', [$code]);
        $client = self::client();
        self::loginAsAdmin($client);
        $path = '/subscribers/' . self::$subscriber['s_uuid'];

        $page = self::request($client, 'GET', $path)['body'];
        self::assertStringContainsString('id="invitation_listId"', $page);
        self::assertStringNotContainsString('subscriber[listId]', $page, 'saving the profile invites nobody');
        self::assertNoNestedForms($path, $page);

        $fields = self::formFields($page, 'invitation');
        $refused = self::region($client, $path . '/invite', 'subscriber-lists', 'POST', $fields, $path);
        self::assertRegion('subscriber-lists', $refused, 422);
        self::assertMatchesRegularExpression('#id="invitation_listId_error1">Choose a list#', $refused['body']);

        $invited = self::region($client, $path . '/invite', 'subscriber-lists', 'POST', ['invitation[listId]' => $listId] + $fields, $path);
        self::assertRegion('subscriber-lists', $invited);
        self::assertStringContainsString('Invited ' . self::EMAIL . ' to Smoke page list', $invited['body'], 'the flash message comes with the card');
        self::assertMatchesRegularExpression('#Smoke page list ' . self::$suffix . ' <code class="small">' . $code . '</code>\s*</span>\s*<span class="d-flex[^"]*">\s*<span class="badge theme-warning">pending</span>#', $invited['body']);
        self::assertSame(1, (int) self::value("SELECT COUNT(*) FROM sendlog WHERE sl_email = ? AND sl_type = 'SUBSCRIBE'", [self::EMAIL]), 'the invitation was sent');

        // Without JavaScript the same form posts and comes back to the page.
        $again = self::request($client, 'POST', $path . '/invite', ['invitation[listId]' => $listId] + $fields);
        self::assertSame(302, $again['status']);
        self::assertStringContainsString('already has a membership of Smoke page list', self::request($client, 'GET', $path)['body']);
        self::assertSame(1, (int) self::value("SELECT COUNT(*) FROM sendlog WHERE sl_email = ? AND sl_type = 'SUBSCRIBE'", [self::EMAIL]), 'not invited twice');
    }

    public function testTheRolesCardAssignsAndRemovesInPlace(): void
    {
        $key = 'smoke-page-role-' . self::$suffix;
        self::$db->prepare('INSERT INTO roles (r_key, r_name) VALUES (?, ?)')->execute([$key, 'Smoke page role']);
        $roleId = (string) self::value('SELECT r_id FROM roles WHERE r_key = ?', [$key]);
        $client = self::client();
        self::loginAsAdmin($client);
        $path = '/subscribers/' . self::$subscriber['s_uuid'];

        $page = self::request($client, 'GET', $path)['body'];
        self::assertStringContainsString('id="subscriber-roles"', $page);
        self::assertStringContainsString('<option value="administrator">Administrator (system)</option>', $page, 'an administrator can be made here');
        $csrf = self::csrfToken($page);

        $assigned = self::region($client, '/roles/assign', 'subscriber-roles', 'POST',
            ['csrf' => $csrf, 'subscriber_id' => (string) self::$subscriber['s_id'], 'role_key' => $key, 'back' => $path], $path);
        self::assertRegion('subscriber-roles', $assigned);
        self::assertStringContainsString('Role assigned.', $assigned['body']);
        self::assertStringContainsString('<span class="badge theme-info">Smoke page role</span>', $assigned['body']);
        self::assertStringNotContainsString('<option value="' . $key . '">', $assigned['body'], 'no longer offered');

        $removed = self::region($client, '/roles/unassign', 'subscriber-roles', 'POST',
            ['csrf' => $csrf, 'subscriber_id' => (string) self::$subscriber['s_id'], 'role_id' => $roleId, 'back' => $path], $path);
        self::assertRegion('subscriber-roles', $removed);
        self::assertStringContainsString('Role removed from the subscriber.', $removed['body']);
        self::assertSame(0, (int) self::value('SELECT COUNT(*) FROM subscriber_roles WHERE sr_s_id = ? AND sr_r_id = ?', [self::$subscriber['s_id'], $roleId]));

        // Without JavaScript the role forms come back to the subscriber page (`back`).
        $plain = self::request($client, 'POST', '/roles/assign', ['csrf' => $csrf, 'subscriber_id' => (string) self::$subscriber['s_id'], 'role_key' => $key, 'back' => $path]);
        self::assertSame(302, $plain['status']);
        self::assertStringEndsWith($path, $plain['location']);

        // The subscriber on their own page: no roles card, no invitation.
        $own = self::client();
        self::assertSame(302, self::signInWithLink($own, self::issueLoginToken(self::$subscriber['s_id']))['status']);
        $mine = self::request($own, 'GET', $path)['body'];
        self::assertStringNotContainsString('id="subscriber-roles"', $mine);
        self::assertStringNotContainsString('id="invitation_listId"', $mine);
        self::assertStringContainsString('Only the subscriber can confirm a membership.', $mine);
    }

    /** The block's own form, no longer inside the profile form, clears the block (it used to save the profile instead). */
    public function testADeliveryBlockIsClearedInPlace(): void
    {
        self::$db->prepare("UPDATE subscribers SET s_delivery_state = 'hard_bounced', s_delivery_state_at = NOW() WHERE s_id = ?")->execute([self::$subscriber['s_id']]);
        $client = self::client();
        self::loginAsAdmin($client);
        $path = '/subscribers/' . self::$subscriber['s_uuid'];
        $page = self::request($client, 'GET', $path)['body'];
        self::assertStringContainsString('Clear the sending block', $page);
        self::assertNoNestedForms($path, $page);

        $cleared = self::region($client, $path . '/delivery-ok', 'subscriber-delivery', 'POST', ['csrf' => self::csrfToken($page)], $path);
        self::assertRegion('subscriber-delivery', $cleared);
        self::assertStringContainsString('No hard bounce or complaint recorded.', $cleared['body']);
        self::assertStringContainsString('block is cleared', $cleared['body']);
        self::assertSame('ok', self::value('SELECT s_delivery_state FROM subscribers WHERE s_id = ?', [self::$subscriber['s_id']]));
    }
}
