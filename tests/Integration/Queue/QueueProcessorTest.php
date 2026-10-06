<?php

declare(strict_types=1);

namespace App\Tests\Integration\Queue;

use App\CattoMail\CattoMailSender;
use App\CattoMail\CattoMailWorker;
use App\CattoMail\OutgoingMessage;
use App\CattoMail\OutgoingRecipient;
use App\CattoMail\SendJobAdmin;
use App\CattoMail\SendOutcome;
use App\CattoMail\UnsubscribeLinks;
use App\Log\MessageLog;
use App\Queue\QueueBuilder;
use App\Queue\QueueProcessor;
use App\Repository\OptionRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\FakeCattoMail;

/**
 * Queue sends through catto-mail: ctnlist renders each recipient's final
 * content and submits it in batches of at most 500 to send jobs of at most
 * 10,000 recipients, with persisted Idempotency-Keys (FakeCattoMail at the
 * HTTP boundary). No campaign mail goes through ctnlist's own SMTP.
 */
final class QueueProcessorTest extends IntegrationTestCase
{
    private FakeCattoMail $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = $this->fakeCattoMail();
    }

    public function testRendersEachRecipientAndSubmitsOneSubscriptionJob(): void
    {
        [$muid, $uuids] = $this->queued(['Ann', 'Ben', 'Cas']);

        $outcome = $this->service(QueueProcessor::class)->process();
        self::assertSame([SendOutcome::SUBMITTED, 3, 3, null], [$outcome->status, $outcome->staged, $outcome->handedOff, $outcome->problem]);

        self::assertCount(1, $this->fake->sendJobs);
        $jobId = $this->fake->lastSendJobId();
        $job = $this->fake->sendJobs[$jobId];
        self::assertSame(['subscription', 'queued', 'News <news.localhost>', ['email' => 'sender@ctnlist.test', 'name' => 'Sender']],
            [$job['message_class'], $job['status'], $job['list_id'], $job['sender_identity']]);
        self::assertSame(['opens' => false, 'clicks' => false], $job['tracking']);
        $runUuid = (string) $this->db->fetchOne("SELECT cr_uuid FROM cattomail_runs WHERE cr_kind = 'campaign' ORDER BY cr_id DESC LIMIT 1");
        self::assertSame($runUuid . '/1', $job['external_reference'], 'the job maps back to the ctnlist run');

        $links = $this->service(UnsubscribeLinks::class);
        foreach ($this->fake->recipients[$jobId] as $n => $recipient) {
            $name = ['Ann', 'Ben', 'Cas'][$n];
            self::assertSame('Campaign', $recipient['subject']);
            self::assertStringStartsWith('<p>Hello ' . $name . '</p>', $recipient['html_body'], 'rendered for this recipient (no merge in catto-mail)');
            self::assertStringStartsWith('Hello ' . $name . ' ', $recipient['text_body']);
            self::assertStringNotContainsString('{firstname}', $recipient['html_body'] . $recipient['text_body']);
            self::assertSame($links->url($uuids[$n], 'NEWS', $muid), $recipient['unsubscribe_url'], 'one-click link without tracking');
            self::assertSame($recipient['external_recipient_reference'],
                $this->db->fetchOne('SELECT crp_uuid FROM cattomail_recipients WHERE crp_s_uuid = ? AND crp_muid = ?', [$uuids[$n], $muid]));
        }

        self::assertEmailCount(0, message: 'no campaign mail through ctnlist SMTP');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT m_sent FROM messages WHERE m_uniqid = ?', [$muid]));
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sendlog WHERE sl_type = 'MESSAGE' AND sl_muid = ? AND sl_list_shortcode = 'NEWS'", [$muid]));
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM cattomail_recipients WHERE crp_muid = ? AND crp_status = 'handed_off' AND crp_html IS NULL", [$muid]),
            'handed off; rendered content dropped once accepted');
        foreach ($uuids as $uuid) {
            self::assertTrue($this->service(MessageLog::class)->wasSent($uuid, $muid));
        }
        self::assertSame('N', $this->service(OptionRepository::class)->get(QueueProcessor::CURRENTLY_SENDING));
        self::assertSame(0, $this->service(QueueBuilder::class)->queueMessage($muid)->queued, 'once-only after delivery');
    }

    public function testMaximumSendIsAHardLimitIncludingZero(): void
    {
        [$muid] = $this->queued(['A', 'B', 'C'], 2);
        self::assertSame(2, $this->service(QueueProcessor::class)->process()->handedOff);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'), 'the rest stays queued');

        $this->db->executeStatement('UPDATE messages SET m_max_send = 0, m_sent = 0 WHERE m_uniqid = ?', [$muid]);
        self::assertSame(0, $this->service(QueueProcessor::class)->process()->staged, 'zero means none');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'));
    }

    public function testDoesNothingWhileAnotherRunIsSending(): void
    {
        $this->queued(['A']);
        $this->service(OptionRepository::class)->set(QueueProcessor::CURRENTLY_SENDING, 'Y');

        self::assertSame(0, $this->service(QueueProcessor::class)->process()->staged);
        self::assertSame('Y', $this->service(OptionRepository::class)->get(QueueProcessor::CURRENTLY_SENDING), 'the other run keeps its flag');
        self::assertSame([], $this->fake->sendJobs);
    }

    public function testRechecksEligibilitySuppressionAndDeliveryStateBeforeEachSend(): void
    {
        [$muid, , $ids] = $this->queued(['A', 'B', 'C']);
        // After queueing: one unsubscribes, one address becomes unusable under the v5 rules, one hard-bounced elsewhere.
        $this->db->executeStatement('UPDATE list_subscribers SET ls_unsubscribed = TRUE WHERE ls_s_id = ?', [$ids[0]]);
        $this->db->executeStatement("UPDATE subscribers SET s_email = 'x@charity.org' WHERE s_id = ?", [$ids[1]]);
        $this->db->executeStatement("UPDATE subscribers SET s_delivery_state = 'hard_bounced' WHERE s_id = ?", [$ids[2]]);

        self::assertSame(0, $this->service(QueueProcessor::class)->process($muid)->staged);
        self::assertSame([], $this->fake->sendJobs);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'), 'ineligible rows removed');
        self::assertSame(
            QueueBuilder::SUPPRESSION_REASON,
            $this->db->fetchOne("SELECT ls_unsubscribe_reason FROM list_subscribers ls JOIN lists l ON l.l_id = ls.ls_l_id WHERE ls_s_id = ? AND l.l_shortcode = 'NEWS'", [$ids[1]])
        );
    }

    public function testMoreThan500RecipientsGoInSeveralBatchesOfOneJob(): void
    {
        $news = $this->createList('NEWS', 'News');
        $this->db->executeStatement(
            "INSERT INTO subscribers (s_email, s_fname) SELECT 'bulk' || n || '@example.com', 'Reader' || n FROM generate_series(1, 501) AS n"
        );
        $this->db->executeStatement("INSERT INTO list_subscribers (ls_s_id, ls_l_id, ls_confirmed) SELECT s_id, ?, TRUE FROM subscribers WHERE s_email LIKE 'bulk%@example.com'", [$news]);
        $muid = $this->createMessage('Big campaign', [$news]);
        self::assertSame(501, $this->service(QueueBuilder::class)->queueMessage($muid)->queued);

        self::assertSame(501, $this->service(QueueProcessor::class)->process()->handedOff);
        self::assertCount(1, $this->fake->sendJobs);
        $batches = $this->fake->requestsTo('POST', '/send-jobs/[0-9a-f-]+/recipients');
        self::assertSame([500, 1], array_map(static fn(array $r): int => count($r['body']['recipients']), $batches));
        self::assertCount(2, array_unique(array_map(static fn(array $r): string => $r['headers']['idempotency-key'], $batches)), 'one key per batch');
        self::assertCount(501, $this->fake->recipients[$this->fake->lastSendJobId()]);
    }

    public function testRunsAreSplitIntoJobsAtTheJobLimit(): void
    {
        $this->service(CattoMailSender::class)->useLimits(2, 5);
        [$muid] = $this->queued(array_map(static fn(int $n): string => 'R' . $n, range(1, 11)));

        self::assertSame(11, $this->service(QueueProcessor::class)->process()->handedOff);
        self::assertSame([5, 5, 1], array_map(static fn(array $recipients): int => count($recipients), array_values($this->fake->recipients)));
        foreach ($this->fake->requestsTo('POST', '/send-jobs/[0-9a-f-]+/recipients') as $batch) {
            self::assertLessThanOrEqual(2, count($batch['body']['recipients']));
        }
        $references = array_column($this->fake->sendJobs, 'external_reference');
        $runUuid = explode('/', $references[0])[0];
        self::assertSame([$runUuid . '/1', $runUuid . '/2', $runUuid . '/3'], $references, 'one coherent ctnlist run');
        self::assertSame(['queued', 'queued', 'queued'], array_column($this->fake->sendJobs, 'status'), 'every job sealed');
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_send_jobs WHERE csj_muid = ?', [$muid]));
    }

    public function testTenThousandAndOneRecipientsMakeTwoJobs(): void
    {
        $sender = $this->service(CattoMailSender::class);
        $message = new OutgoingMessage(str_repeat('a', 32), '', '', OutgoingMessage::TRANSACTIONAL, 'sender@ctnlist.test', 'Sender');
        $run = $sender->startRun('campaign', str_repeat('a', 32));
        for ($n = 1; $n <= 10001; $n++) {
            $sender->stage($run, $message, new OutgoingRecipient("r{$n}@example.com", null, 'MESSAGE', 'Subject', '<p>' . $n . '</p>', (string) $n));
        }
        $outcome = $sender->finishRun($run, 10001);
        self::assertSame(10001, $outcome->handedOff);
        self::assertSame([10000, 1], array_map(static fn(array $recipients): int => count($recipients), array_values($this->fake->recipients)));
        self::assertCount(21, $this->fake->requestsTo('POST', '/send-jobs/[0-9a-f-]+/recipients'), '20 batches of 500 and one of 1');
    }

    public function testALostBatchResponseIsRetriedWithoutDuplicates(): void
    {
        $this->queued(['A', 'B', 'C']);
        // catto-mail accepts the batch, but the response never arrives; the retry replays it.
        $this->fake->failNext('POST /send-jobs/[0-9a-f-]+/recipients', 'timeout', afterEffect: true);
        $this->fake->failNext('POST /send-jobs', 'timeout', afterEffect: true);

        self::assertSame(3, $this->service(QueueProcessor::class)->process()->handedOff);
        self::assertCount(1, $this->fake->sendJobs, 'one send job');
        self::assertCount(3, $this->fake->recipients[$this->fake->lastSendJobId()], 'no duplicate recipients');
        $batches = $this->fake->requestsTo('POST', '/send-jobs/[0-9a-f-]+/recipients');
        self::assertCount(2, $batches);
        self::assertSame($batches[0]['headers']['idempotency-key'], $batches[1]['headers']['idempotency-key'], 'the retry reuses the key');
        self::assertSame($batches[0]['body'], $batches[1]['body'], 'and the identical body');
    }

    public function testUnreachableCattoMailLeavesTheWorkForTheWorker(): void
    {
        [$muid] = $this->queued(['A', 'B', 'C']);
        $this->fake->failNext('POST /send-jobs/[0-9a-f-]+/submit', 'timeout', times: 3);

        $outcome = $this->service(QueueProcessor::class)->process();
        self::assertSame([SendOutcome::DEFERRED, 3, 0], [$outcome->status, $outcome->staged, $outcome->handedOff]);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'), 'moved to the outbox, not lost');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM sendlog WHERE sl_muid = ?', [$muid]), 'not handed off yet');

        $report = $this->service(CattoMailWorker::class)->run();
        self::assertSame(1, $report['send jobs flushed']);
        self::assertCount(1, $this->fake->sendJobs);
        self::assertSame('queued', $this->fake->sendJobs[$this->fake->lastSendJobId()]['status']);
        self::assertCount(3, $this->fake->recipients[$this->fake->lastSendJobId()]);
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sendlog WHERE sl_muid = ? AND sl_type = 'MESSAGE'", [$muid]));
        self::assertSame(0, $this->service(CattoMailWorker::class)->run()['send jobs flushed'], 'nothing left to do');
    }

    public function testAJobThatCannotBeCreatedKeepsTheQueue(): void
    {
        [$muid] = $this->queued(['A', 'B']);
        $this->fake->failNext('POST /send-jobs', '503', times: 3);
        $outcome = $this->service(QueueProcessor::class)->process();
        self::assertSame([SendOutcome::DEFERRED, 0], [$outcome->status, $outcome->staged]);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_send_jobs WHERE csj_muid = ?', [$muid]), 'the empty job is discarded');

        self::assertSame(2, $this->service(QueueProcessor::class)->process()->handedOff, 'a later run sends');
        self::assertCount(1, $this->fake->sendJobs);
    }

    public function testARefusedSenderDomainSendsNothing(): void
    {
        [$muid] = $this->queued(['A', 'B']);
        $this->db->executeStatement("UPDATE messages SET m_from_address = 'news@unregistered.test' WHERE m_uniqid = ?", [$muid]);

        $outcome = $this->service(QueueProcessor::class)->process();
        self::assertSame(SendOutcome::FAILED, $outcome->status);
        self::assertStringContainsString('registered sending domain', (string) $outcome->problem);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'), 'still queued');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT m_sent FROM messages WHERE m_uniqid = ?', [$muid]));
    }

    public function testARefusedJobCanBeReturnedToTheQueue(): void
    {
        [$muid] = $this->queued(['A', 'B']);
        $this->fake->failNext('POST /send-jobs/[0-9a-f-]+/recipients', '422');

        $outcome = $this->service(QueueProcessor::class)->process();
        self::assertSame(SendOutcome::FAILED, $outcome->status);
        $jobId = (int) $this->db->fetchOne('SELECT csj_id FROM cattomail_send_jobs WHERE csj_muid = ?', [$muid]);
        self::assertSame('failed', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_id = ?', [$jobId]));
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM cattomail_recipients WHERE crp_csj_id = ? AND crp_status = 'not_sent'", [$jobId]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM sendlog WHERE sl_muid = ?', [$muid]));

        self::assertSame(2, $this->service(SendJobAdmin::class)->requeueFailed($jobId));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT m_sent FROM messages WHERE m_uniqid = ?', [$muid]));
        self::assertSame(2, $this->service(QueueProcessor::class)->process()->handedOff);
    }

    /**
     * @param list<string> $firstNames one subscriber each
     * @return array{0: string, 1: list<string>, 2: list<int>} MUID, subscriber UUIDs and ids
     */
    private function queued(array $firstNames, int $maxSend = 1000000): array
    {
        $news = $this->createList('NEWS', 'News');
        $uuids = [];
        $ids = [];
        foreach ($firstNames as $i => $name) {
            $ids[] = $id = $this->createSubscriber('reader' . ($i + 1) . '@example.com', $name);
            $this->setMembership($id, $news, true);
            $uuids[] = $this->subscriberUuid($id);
        }
        $muid = $this->createMessage('Campaign', [$news], $maxSend);
        self::assertSame(count($firstNames), $this->service(QueueBuilder::class)->queueMessage($muid)->queued);
        return [$muid, $uuids, $ids];
    }
}
