<?php

declare(strict_types=1);

namespace App\Tests\Integration\Queue;

use App\Log\MessageLog;
use App\Queue\QueueBuilder;
use App\Tests\Integration\IntegrationTestCase;

final class QueueBuilderTest extends IntegrationTestCase
{
    public function testUnionOfSelectedListsQueuedOnceEach(): void
    {
        $news = $this->createList('NEWS', 'News');
        $deals = $this->createList('DEALS', 'Deals');
        $other = $this->createList('OTHER', 'Other');
        $both = $this->createSubscriber('both@example.com');
        $this->setMembership($both, $news, true);
        $this->setMembership($both, $deals, true);
        $pending = $this->createSubscriber('pending@example.com');
        $this->setMembership($pending, $news, false);
        $gone = $this->createSubscriber('gone@example.com');
        $this->setMembership($gone, $deals, true, true);
        $elsewhere = $this->createSubscriber('elsewhere@example.com');
        $this->setMembership($elsewhere, $other, true);
        $this->setMembership($elsewhere, $this->listId('ALL'), true);
        $muid = $this->createMessage('Union', [$news, $deals]);

        $outcome = $this->service(QueueBuilder::class)->queueMessage($muid);

        self::assertNull($outcome->problem);
        self::assertSame(1, $outcome->queued, 'only confirmed, subscribed members of the selected lists; ALL is not implied');
        self::assertSame([['both@example.com', 'DEALS']], $this->queueRows($muid), 'one row, list context by shortcode order');
        self::assertSame(
            ['s_priority' => 0, 's_emailsleft' => 0],
            $this->db->fetchAssociative('SELECT s_priority, s_emailsleft FROM subscribers WHERE s_id = ?', [$both]),
            'queue-time subscriber state'
        );
        self::assertSame(1, (int) $this->db->fetchOne('SELECT m_queued FROM messages WHERE m_uniqid = ?', [$muid]));
        self::assertNotNull($this->db->fetchOne('SELECT m_datesent FROM messages WHERE m_uniqid = ?', [$muid]), 'prepared for queueing');
        self::assertTrue($this->service(MessageLog::class)->hasRecord($this->subscriberUuid($both), $muid), 'smlog row created at queue time');

        self::assertSame(0, $this->service(QueueBuilder::class)->queueMessage($muid)->queued, 're-queueing adds nobody');
    }

    public function testSmlogRecordBlocksQueueing(): void
    {
        $news = $this->createList('NEWS', 'News');
        $subscriber = $this->createSubscriber('jane@example.com');
        $this->setMembership($subscriber, $news, true);
        $muid = $this->createMessage('Once', [$news]);
        $this->service(MessageLog::class)->ensure($this->subscriberUuid($subscriber), $muid);

        self::assertSame(0, $this->service(QueueBuilder::class)->queueMessage($muid)->queued, 'once-only: already logged for this message');
    }

    public function testLimitAndEngagementOrder(): void
    {
        $news = $this->createList('NEWS', 'News');
        foreach (['quiet@example.com' => null, 'old@example.com' => '2026-01-01 00:00:00', 'recent@example.com' => '2026-09-01 00:00:00'] as $email => $interacted) {
            $id = $this->createSubscriber($email);
            $this->setMembership($id, $news, true);
            $this->db->executeStatement('UPDATE subscribers SET s_last_interacted = ? WHERE s_id = ?', [$interacted, $id]);
        }
        $muid = $this->createMessage('Limited', [$news]);

        self::assertSame(2, $this->service(QueueBuilder::class)->queueMessage($muid, 2)->queued);
        self::assertSame(['recent@example.com', 'old@example.com'], array_column($this->queueRows($muid), 0), 'engaged subscribers first');
    }

    public function testSuppressedSubscribersLoseAllMemberships(): void
    {
        $news = $this->createList('NEWS', 'News');
        $deals = $this->createList('DEALS', 'Deals');
        // The v5 cleanup rules make .org addresses unusable, which counts as suppressed.
        $blocked = $this->createSubscriber('joe@charity.org');
        $this->setMembership($blocked, $news, true);
        $this->setMembership($blocked, $deals, true);
        $muid = $this->createMessage('Suppressed', [$news]);

        self::assertSame(0, $this->service(QueueBuilder::class)->queueMessage($muid)->queued);
        self::assertSame(
            0,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND (ls_confirmed OR NOT ls_unsubscribed)', [$blocked]),
            'every membership made ineligible, not only the message\'s lists'
        );
        self::assertSame(
            QueueBuilder::SUPPRESSION_REASON,
            $this->db->fetchOne('SELECT ls_unsubscribe_reason FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$blocked, $deals])
        );
    }

    public function testDraftWithoutListsIsRefusedAndUnaltered(): void
    {
        $muid = $this->createMessage('Draft');
        $outcome = $this->service(QueueBuilder::class)->queueMessage($muid);

        self::assertSame(0, $outcome->queued);
        self::assertStringContainsString('no target lists', (string) $outcome->problem);
        self::assertNull($this->db->fetchOne('SELECT m_datesent FROM messages WHERE m_uniqid = ?', [$muid]), 'not prepared');
        self::assertNotNull($this->service(QueueBuilder::class)->queueMessage(str_repeat('c', 32))->problem, 'unknown message');
    }

    public function testRotationGivesEachSubscriberOneMessage(): void
    {
        $news = $this->createList('NEWS', 'News');
        foreach (['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'] as $email) {
            $this->setMembership($this->createSubscriber($email), $news, true);
        }
        $first = $this->createMessage('First', [$news]);
        $second = $this->createMessage('Second', [$news]);
        $draft = $this->createMessage('Draft');

        $outcome = $this->service(QueueBuilder::class)->queueRotation([$first, $second, $draft, $first], 3);

        self::assertSame(3, $outcome->queued, 'volume is the total across messages');
        self::assertCount(2, $this->queueRows($first), 'rotation: first, second, first');
        self::assertCount(1, $this->queueRows($second));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(DISTINCT q_s_uuid) FROM queue'), 'each subscriber at most once');
        self::assertCount(0, $this->queueRows($draft), 'messages without lists are skipped');
        self::assertNotNull($this->service(QueueBuilder::class)->queueRotation([], 10)->problem);
    }

    /** @return list<array{0: string, 1: string}> email and list per queued row, in send order */
    private function queueRows(string $muid): array
    {
        return array_map(
            static fn(array $row): array => [(string) $row['q_email'], (string) $row['q_list_shortcode']],
            $this->db->fetchAllAssociative(
                'SELECT q_email, q_list_shortcode FROM queue WHERE q_muid = ?
                 ORDER BY q_mpriority DESC, (q_last_interacted IS NULL) ASC, q_last_interacted DESC, q_spriority DESC, q_id ASC',
                [$muid]
            )
        );
    }
}
