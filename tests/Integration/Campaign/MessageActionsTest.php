<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Campaign\ForwardService;
use App\Campaign\ReactionService;
use App\Log\MessageLog;
use App\Repository\MessageRepository;
use App\Subscriber\SubscriptionService;
use App\Tests\Integration\IntegrationTestCase;

/** Subscribing, reactions and forwarding (the services behind the recipient links). */
final class MessageActionsTest extends IntegrationTestCase
{
    public function testSubscribeInvitesWithoutGrantingConsent(): void
    {
        $subscriptions = $this->service(SubscriptionService::class);
        $news = $this->createList('NEWS', 'News');

        self::assertTrue($subscriptions->subscribe('New@Example.com', 50, $news));
        self::assertEmailCount(2, message: 'new identity: ALL and NEWS invitations');
        $id = (int) $this->db->fetchOne("SELECT s_id FROM subscribers WHERE s_email = 'new@example.com'");
        self::assertSame(50, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND ls_confirmed', [$id]), 'no consent');

        self::assertTrue($subscriptions->subscribe('new@example.com', 99999999, $news));
        self::assertEmailCount(2, message: 'existing membership: no new invitation');
        self::assertSame(10000000, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]), 'capped');

        self::assertTrue($subscriptions->subscribe('other@example.com', 0, $this->listId('ALL')));
        self::assertEmailCount(3, message: 'new identity joining ALL: one invitation');

        self::assertFalse($subscriptions->subscribe('joe@charity.org', 0, $news), 'suppressed under the v5 rules');
        self::assertFalse($subscriptions->subscribe('x@example.com', 0, 999999), 'unknown list');
    }

    public function testReactionsOnlyForDeliveredMessages(): void
    {
        [$uuid, $muid, $id] = $this->delivered();
        $reactions = $this->service(ReactionService::class);
        self::assertFalse($reactions->like($this->subscriberUuid($this->createSubscriber('stranger@example.com')), $muid));

        self::assertTrue($reactions->open($uuid, $muid));
        self::assertTrue($reactions->like($uuid, $muid));
        self::assertSame(1001, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]), 'open +1, like +1000');
        self::assertTrue($reactions->dislike($uuid, $muid));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]), 'a dislike resets');

        $counts = $this->db->fetchAssociative('SELECT m_reads, m_likes, m_dislikes FROM messages WHERE m_uniqid = ?', [$muid]);
        self::assertSame(['m_reads' => 3, 'm_likes' => 1, 'm_dislikes' => 1], $counts);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT sml_reads FROM smlog WHERE sml_muid = ? AND sml_s_uuid = ?', [$muid, $uuid]));
    }

    public function testForwardSendsCampaignCopiesAndNotifies(): void
    {
        $fake = $this->fakeCattoMail();
        [$uuid, $muid, $id] = $this->delivered();
        $message = $this->service(MessageRepository::class)->findByMuid($muid);
        self::assertNotNull($message);
        $forwards = $this->service(ForwardService::class);

        $outcome = $forwards->forward($uuid, $message, "friend@example.com, joe@charity.org\nfriend@example.com");

        self::assertSame(['sent' => ['friend@example.com'], 'problem' => null], $outcome, 'duplicates removed, suppressed skipped');
        $friend = (int) $this->db->fetchOne("SELECT s_id FROM subscribers WHERE s_email = 'friend@example.com'");
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM message_forwards WHERE mf_sender_s_id = ? AND mf_recipient_s_id = ?', [$id, $friend]));
        self::assertSame(
            ['FORWARD-MESSAGE|NEWS|friend@example.com', 'FORWARD-NOTIFICATION|NEWS|jane@example.com'],
            array_map('strval', $this->db->fetchFirstColumn("SELECT sl_type || '|' || sl_list_shortcode || '|' || sl_email FROM sendlog WHERE sl_type LIKE 'FORWARD%' ORDER BY sl_id")),
            'the copy keeps the forwarder\'s list context'
        );
        self::assertTrue($this->service(MessageLog::class)->wasSent($this->subscriberUuid($friend), $muid));
        self::assertSame(['friend@example.com'], array_column($fake->recipients[$fake->lastSendJobId()], 'email_address'), 'the copy went through catto-mail');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_forwards FROM smlog WHERE sml_muid = ? AND sml_s_uuid = ?', [$muid, $uuid]));
        self::assertSame(5, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]));

        $many = implode(' ', array_map(static fn(int $i): string => "f{$i}@example.com", range(1, 11)));
        self::assertNotNull($forwards->forward($uuid, $message, $many)['problem']);
        self::assertSame('No valid email addresses were supplied.', $forwards->forward($uuid, $message, 'nobody')['problem']);
    }

    /** @return array{0: string, 1: string, 2: int} subscriber UUID, MUID and subscriber id of a delivered message */
    private function delivered(): array
    {
        $id = $this->createSubscriber('jane@example.com');
        $uuid = $this->subscriberUuid($id);
        $muid = $this->createMessage('Delivered', [$this->createList('NEWS', 'News')]);
        $this->service(MessageLog::class)->markSent($uuid, $muid, 'NEWS');
        return [$uuid, $muid, $id];
    }
}
