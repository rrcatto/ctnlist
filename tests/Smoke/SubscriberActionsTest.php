<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * A subscriber's Actions menu (owner decision 2026-10-27) on the Subscribers list and the subscriber
 * page: every action with its icon and title, unsubscribing from one list or all, and blocking and
 * unblocking the address and its domain. The smoke installation has no mail service, so a block
 * waits there as pending, and stops the address here at once.
 */
final class SubscriberActionsTest extends SmokeTestCase
{
    private static string $email;
    private static string $domain;
    /** @var array{s_id: int, s_uuid: string} */
    private static array $subscriber;
    private static int $listId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $suffix = bin2hex(random_bytes(3));
        self::$domain = 'smoke-actions-' . $suffix . '.com';
        self::$email = 'smoke-actions@' . self::$domain;
        self::$db->prepare('INSERT INTO subscribers (s_email, s_fname) VALUES (?, ?)')->execute([self::$email, 'Actions']);
        $row = self::rows('SELECT s_id, s_uuid FROM subscribers WHERE LOWER(s_email) = ?', [self::$email])[0];
        self::$subscriber = ['s_id' => (int) $row['s_id'], 's_uuid' => (string) $row['s_uuid']];
        self::$db->prepare('INSERT INTO lists (l_shortcode, l_name) VALUES (?, ?)')->execute(['SA' . strtoupper(substr($suffix, 0, 4)), 'Smoke actions ' . $suffix]);
        self::$listId = (int) self::value('SELECT l_id FROM lists WHERE l_name = ?', ['Smoke actions ' . $suffix]);
        foreach ([self::$listId, (int) self::value("SELECT l_id FROM lists WHERE l_shortcode = 'ALL'")] as $list) {
            self::$db->prepare('INSERT INTO list_subscribers (ls_s_id, ls_l_id, ls_confirmed) VALUES (?, ?, TRUE) ON CONFLICT DO NOTHING')->execute([self::$subscriber['s_id'], $list]);
        }
    }

    private static function active(): int
    {
        return (int) self::value('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND NOT ls_unsubscribed', [self::$subscriber['s_id']]);
    }

    public function testEveryActionHasAnIconAndATitle(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $list = self::request($client, 'GET', '/subscribers?e=' . urlencode(self::$email))['body'];
        self::assertNoNestedForms('/subscribers', $list);
        $menu = self::match('#<div class="menu" aria-labelledby="actions-' . self::$subscriber['s_uuid'] . '">(.*?)</div>\s*</div>#s', $list, 'the Actions menu of the row')[1];
        foreach (['Edit details', 'Invite to a list', 'Unsubscribe from a list…', 'Unsubscribe from all lists', 'Block this address',
            'Block every address at @' . self::$domain, 'Send history'] as $title) {
            self::assertMatchesRegularExpression('#<i class="bi bi-[a-z0-9-]+" aria-hidden="true"></i>' . preg_quote($title, '#') . '<#', $menu, $title);
        }
        preg_match_all('#class="menu-item"[^>]*>\s*<i class="bi (bi-[a-z0-9-]+)"#', $menu, $icons);
        self::assertGreaterThanOrEqual(7, count($icons[1]));
        self::assertSame(count(array_unique($icons[1])), count($icons[1]), 'no two actions share an icon');

        $page = self::request($client, 'GET', '/subscribers/' . self::$subscriber['s_uuid'])['body'];
        self::assertNoNestedForms('the subscriber page', $page);
        self::assertStringContainsString('Block this address', $page);
        self::assertStringContainsString('>Unsubscribe</button>', $page, 'each list it belongs to has its own Unsubscribe');
    }

    public function testUnsubscribingFromOneListThenFromEvery(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $path = '/subscribers/' . self::$subscriber['s_uuid'];
        $page = self::request($client, 'GET', $path)['body'];
        $one = self::region($client, $path . '/lists/' . self::$listId . '/unsubscribe', 'subscriber-lists', 'POST', ['csrf' => self::csrfToken($page)], $path);
        self::assertRegion('subscriber-lists', $one);
        self::assertStringContainsString('is unsubscribed from Smoke actions', $one['body']);
        self::assertSame(1, self::active(), 'only that list');

        $all = self::region($client, $path . '/unsubscribe-all', 'subscriber-results', 'POST', ['csrf' => self::csrfToken($page), 'return' => 'list'], '/subscribers');
        self::assertRegion('subscriber-results', $all);
        self::assertSame(0, self::active());
        self::assertSame('Unsubscribed from every list by an administrator',
            self::value('SELECT ls_unsubscribe_reason FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id <> ?', [self::$subscriber['s_id'], self::$listId]), 'the list left second');
        self::assertSame(403, self::request($client, 'POST', $path . '/unsubscribe-all', ['csrf' => 'forged'])['status'], 'CSRF');
    }

    public function testBlockingAndUnblockingTheAddressAndItsDomain(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $path = '/subscribers/' . self::$subscriber['s_uuid'];
        $csrf = self::csrfToken(self::request($client, 'GET', $path)['body']);

        $blocked = self::request($client, 'POST', $path . '/block', ['csrf' => $csrf]);
        self::assertSame(302, $blocked['status']);
        $page = self::request($client, 'GET', $path)['body'];
        self::assertStringContainsString('This address is blocked', $page);
        self::assertStringContainsString('Unblock this address', $page);
        self::assertSame(['address', 'pending'], array_values(self::rows('SELECT cbl_scope, cbl_status FROM cattomail_blocks WHERE cbl_value = ?', [self::$email])[0]),
            'kept for the mail service, which this installation does not have');

        self::request($client, 'POST', $path . '/block-domain', ['csrf' => $csrf]);
        self::assertSame('pending', self::value("SELECT cbl_status FROM cattomail_blocks WHERE cbl_scope = 'domain' AND cbl_value = ?", [self::$domain]));
        $list = self::request($client, 'GET', '/subscribers?e=' . urlencode(self::$email))['body'];
        self::assertStringContainsString('Unblock @' . self::$domain, $list);
        self::assertMatchesRegularExpression('#bi-slash-circle[^<]*</i>Blocked</span>#', $list, 'the row says so');

        foreach (['address', 'domain'] as $scope) {
            self::request($client, 'POST', $path . '/unblock', ['csrf' => $csrf, 'scope' => $scope]);
        }
        self::assertSame(['lifted', 'lifted'], array_column(self::rows('SELECT cbl_status FROM cattomail_blocks WHERE cbl_value IN (?, ?) ORDER BY cbl_scope', [self::$email, self::$domain]), 'cbl_status'),
            'never sent to a mail service: ended here');
        self::assertStringNotContainsString('This address is blocked', self::request($client, 'GET', $path)['body']);
    }
}
