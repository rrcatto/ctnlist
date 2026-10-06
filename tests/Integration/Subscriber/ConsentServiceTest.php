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
