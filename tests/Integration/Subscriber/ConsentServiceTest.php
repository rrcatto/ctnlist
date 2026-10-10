<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriber;

use App\Log\MessageLog;
use App\Repository\ListRepository;
use App\Security\SubscriberUserProvider;
use App\Subscriber\ConsentService;
use App\Tests\Integration\IntegrationTestCase;

final class ConsentServiceTest extends IntegrationTestCase
{
    public function testConfirmGrantsConsentWithTheV5Effects(): void
    {
        $id = $this->createSubscriber('jane@example.com', 'Jane');
        $this->db->executeStatement('UPDATE subscribers SET s_priority = 5 WHERE s_id = ?', [$id]);
        $news = $this->createList('NEWS', 'News');
        $muid = $this->createMessage('Invite', [$news]);
        $user = $this->service(SubscriberUserProvider::class)->loadUserBySubscriberId($id);
        $list = $this->service(ListRepository::class)->findById($news);
        self::assertNotNull($list);

        $message = $this->service(ConsentService::class)->confirm($user, $list, $muid);

        self::assertSame('Your subscription to News is confirmed.', $message);
        self::assertTrue($this->eligible($id, $news));
        self::assertSame(['joined', 'confirmed'], $this->events($id, $news), 'audit trail as in v5');
        self::assertSame(15, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_confirms FROM smlog WHERE sml_muid = ?', [$muid]));
        self::assertSame('CONFIRM|NEWS', $this->db->fetchOne("SELECT sl_type || '|' || sl_list_shortcode FROM sendlog ORDER BY sl_id DESC LIMIT 1"));
    }

    public function testSuppressedAddressesCannotBeConfirmed(): void
    {
        $id = $this->createSubscriber('joe@charity.org');
        $user = $this->service(SubscriberUserProvider::class)->loadUserBySubscriberId($id);
        $all = $this->service(ListRepository::class)->findByShortcode('ALL');
        self::assertNotNull($all);

        self::assertStringContainsString('globally suppressed', $this->service(ConsentService::class)->confirm($user, $all));
        self::assertFalse($this->eligible($id, $all['l_id']));
        self::assertEmailCount(0);
    }

    public function testUnsubscribeFromOneListOrEverywhere(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $news = $this->createList('NEWS', 'News');
        $deals = $this->createList('DEALS', 'Deals');
        $this->setMembership($id, $news, true);
        $this->setMembership($id, $deals, true);
        $this->db->executeStatement("UPDATE subscribers SET s_priority = 9, s_last_interacted = '2026-01-01' WHERE s_id = ?", [$id]);
        $user = $this->service(SubscriberUserProvider::class)->loadUserBySubscriberId($id);
        $lists = $this->service(ListRepository::class);
        $consent = $this->service(ConsentService::class);
        $muid = $this->createMessage('Offer', [$news]);
        $this->service(MessageLog::class)->ensure($user->uuid, $muid, 'NEWS');

        $list = $lists->findById($news) ?? self::fail('list');
        $message = $consent->unsubscribe($user, $list, 'list', 'Too many', $muid);
        self::assertSame('You have been unsubscribed from News.', $message);
        self::assertFalse($this->eligible($id, $news));
        self::assertTrue($this->eligible($id, $deals), 'other lists untouched');
        self::assertSame(['s_priority' => 0, 's_last_interacted' => null], $this->db->fetchAssociative('SELECT s_priority, s_last_interacted FROM subscribers WHERE s_id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_unsubscribe FROM smlog WHERE sml_muid = ?', [$muid]));
        self::assertSame('UNSUBSCRIBE', $this->db->fetchOne('SELECT sl_type FROM sendlog ORDER BY sl_id DESC LIMIT 1'));

        $message = $consent->unsubscribe($user, $list, 'spam', '');
        self::assertStringContainsString('all local lists', $message);
        self::assertFalse($this->eligible($id, $deals), 'a global unsubscribe disables every membership');
    }

    /**
     * The one-click link needs no sign-in and can be replayed: a repeated unsubscribe changes
     * nothing, keeps the reason first given and notifies nobody again.
     */
    public function testARepeatedUnsubscribeChangesAndSendsNothing(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $news = $this->createList('NEWS', 'News');
        $this->setMembership($id, $news, true);
        $subscriber = $this->db->fetchAssociative('SELECT s_id, s_uuid, s_email, s_fname, s_lname FROM subscribers WHERE s_id = ?', [$id]) ?: self::fail('subscriber');
        /** @var array{s_id: int, s_uuid: string, s_email: string, s_fname: string, s_lname: string} $subscriber */
        $list = $this->service(ListRepository::class)->findById($news) ?? self::fail('list');
        $user = $this->service(SubscriberUserProvider::class)->loadUserBySubscriberId($id);
        $consent = $this->service(ConsentService::class);

        self::assertSame('You have been unsubscribed from News.', $consent->unsubscribe($user, $list, 'list', 'First reason'));
        self::assertEmailCount(1);
        self::assertSame('You have been unsubscribed from News.', $consent->unsubscribeByLink($subscriber, $list));
        self::assertSame('You have been unsubscribed from News.', $consent->unsubscribeByLink($subscriber, $list));
        self::assertEmailCount(1, message: 'no second notification');
        self::assertSame('First reason', $this->db->fetchOne('SELECT ls_unsubscribe_reason FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$id, $news]));

        $consent->unsubscribe($user, $list, 'global', 'Everything');
        self::assertSame('First reason', $this->db->fetchOne('SELECT ls_unsubscribe_reason FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$id, $news]),
            'a global unsubscribe keeps the reason of a list left earlier');
        self::assertSame(['joined', 'unsubscribed'], $this->events($id, $news), 'one unsubscribe event');
    }

    /** No suppression database here (SUPPRESSION_PROVIDER=none): the subscriber is not told it was recorded. */
    public function testAGlobalUnsubscribeSaysWhenTheSuppressionDatabaseWasNotUpdated(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $user = $this->service(SubscriberUserProvider::class)->loadUserBySubscriberId($id);
        $all = $this->service(ListRepository::class)->findByShortcode('ALL') ?? self::fail('ALL');

        self::assertSame('You have been unsubscribed from all local lists, but the global suppression database could not be updated. Please try again later.',
            $this->service(ConsentService::class)->unsubscribe($user, $all, 'global', ''));
    }

    /**
     * PostgreSQL runs in UTC; the times it fills in (DEFAULT CURRENT_TIMESTAMP, the consent-event
     * trigger) must be in PHP's timezone, like every time ctnlist writes itself.
     */
    public function testDatabaseFilledTimesAreInTheApplicationsTimezone(): void
    {
        self::assertSame(date_default_timezone_get(), $this->db->fetchOne('SHOW timezone'));
        $id = $this->createSubscriber('jane@example.com');
        $news = $this->createList('NEWS', 'News');
        $this->service(\App\Repository\MembershipRepository::class)->ensure($id, $news);
        $times = $this->db->fetchAssociative(
            'SELECT ls.ls_subscribed_at, e.lse_created_at FROM list_subscribers ls JOIN list_subscription_events e ON e.lse_ls_id = ls.ls_id
             WHERE ls.ls_s_id = ? AND ls.ls_l_id = ?', [$id, $news]) ?: self::fail('membership');
        foreach ($times as $column => $time) {
            self::assertLessThan(300, abs(time() - (int) strtotime((string) $time)), $column . ' ' . $time . ' is local time');
        }
    }

    /** Consent evidence is append-only in the database itself, and keeps its subscriber and list. */
    public function testConsentEventsCannotBeChangedOrOrphaned(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $news = $this->createList('NEWS', 'News');
        $this->setMembership($id, $news, true);
        $event = (int) $this->db->fetchOne('SELECT e.lse_id FROM list_subscription_events e JOIN list_subscribers ls ON ls.ls_id = e.lse_ls_id WHERE ls.ls_s_id = ? LIMIT 1', [$id]);

        foreach ([
            'update an event' => ["UPDATE list_subscription_events SET lse_event = 'unsubscribed' WHERE lse_id = ?", [$event]],
            'delete an event' => ['DELETE FROM list_subscription_events WHERE lse_id = ?', [$event]],
            'delete the subscriber' => ['DELETE FROM subscribers WHERE s_id = ?', [$id]],
            'delete the list' => ['DELETE FROM lists WHERE l_id = ?', [$news]],
        ] as $attempt => [$sql, $params]) {
            $this->db->beginTransaction();
            try {
                $this->db->executeStatement($sql, $params);
                self::fail($attempt . ' was allowed');
            } catch (\Doctrine\DBAL\Exception\DriverException) {
            } finally {
                $this->db->rollBack();
            }
        }
        self::assertSame(['joined'], $this->events($id, $news));
    }

    private function eligible(int $subscriberId, int $listId): bool
    {
        $row = $this->db->fetchAssociative('SELECT ls_confirmed, ls_unsubscribed FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$subscriberId, $listId]);
        return $row !== false && $row['ls_confirmed'] && !$row['ls_unsubscribed'];
    }

    /** @return list<string> */
    private function events(int $subscriberId, int $listId): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT e.lse_event FROM list_subscription_events e JOIN list_subscribers ls ON ls.ls_id = e.lse_ls_id
             WHERE ls.ls_s_id = ? AND ls.ls_l_id = ? ORDER BY e.lse_id',
            [$subscriberId, $listId]
        ));
    }
}
