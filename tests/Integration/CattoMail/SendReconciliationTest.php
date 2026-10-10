<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\CattoMailWorker;

/** Webhooks are notifications, not the only source of truth: polling resolves send jobs whose webhook never came. */
final class SendReconciliationTest extends CattoMailTestCase
{
    public function testPollingResolvesASendJobWhoseWebhookWasMissed(): void
    {
        $sent = $this->sentCampaign(['ann@example.com', 'ben@example.com', 'cas@example.com']);
        $this->fake->deliver($sent['job'], ['ben@example.com' => 'hard_bounced', 'cas@example.com' => 'complained']);
        // No send.completed, message.hard_bounced or message.complained webhook arrives.
        $worker = $this->service(CattoMailWorker::class);
        self::assertSame(0, $worker->run()['send jobs reconciled'], 'not due yet');

        $this->db->executeStatement("UPDATE cattomail_send_jobs SET csj_last_checked_at = csj_last_checked_at - INTERVAL '1 hour' WHERE csj_remote_id = ?", [$sent['job']]);
        self::assertSame(1, $worker->run()['send jobs reconciled']);

        self::assertSame('completed', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_remote_id = ?', [$sent['job']]));
        self::assertSame(['remote_accepted', 'hard_bounced', 'complained'], $this->db->fetchFirstColumn('SELECT crp_status FROM cattomail_recipients WHERE crp_muid = ? ORDER BY crp_id', [$sent['muid']]));
        self::assertSame(['ok', 'hard_bounced', 'complained'], array_map(fn(int $id): string => (string) $this->db->fetchOne('SELECT s_delivery_state FROM subscribers WHERE s_id = ?', [$id]), $sent['ids']),
            'the same effects as the webhooks would have had');
        self::assertNotFalse($this->db->fetchOne('SELECT 1 FROM cattomail_recipients WHERE crp_email = ? AND crp_remote_message_id IS NOT NULL', ['ann@example.com']), 'message ids mapped for event lookups');

        // A webhook arriving late afterwards changes nothing further.
        $this->webhook('message.hard_bounced', $this->messageData($sent['job'], 'ben@example.com', 'hard_bounced', 'hard_bounce'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT s_bounces FROM subscribers WHERE s_id = ?', [$sent['ids'][1]]));
        $this->db->executeStatement("UPDATE cattomail_send_jobs SET csj_last_checked_at = csj_last_checked_at - INTERVAL '1 hour' WHERE csj_remote_id = ?", [$sent['job']]);
        self::assertSame(0, $worker->run()['send jobs reconciled'], 'final jobs are not polled again');
    }

    /**
     * catto-mail sealed the job, but its answer never reached ctnlist (here: lost responses; in
     * production also a process that stopped before recording the handoff), and a webhook reports
     * the job before the worker retries. Its recipients still reach the Send Log and smlog, once.
     */
    public function testAJobSealedWithoutALocalHandoffIsHandedOffWhenCattoMailReportsIt(): void
    {
        $news = $this->createList('NEWS', 'News');
        $uuids = [];
        foreach (['ann@example.com', 'ben@example.com'] as $email) {
            $id = $this->createSubscriber($email);
            $this->setMembership($id, $news, true);
            $uuids[] = $this->subscriberUuid($id);
        }
        $muid = $this->createMessage('Campaign', [$news]);
        $this->service(\App\Queue\QueueBuilder::class)->queueMessage($muid);
        $this->fake->failNext('POST /send-jobs/[0-9a-f-]+/submit', 'timeout', afterEffect: true, times: 10);

        $outcome = $this->service(\App\Queue\QueueProcessor::class)->process();
        self::assertSame([2, 0], [$outcome->staged, $outcome->handedOff], 'sealed at catto-mail, not recorded here');
        $job = $this->fake->lastSendJobId();
        self::assertSame('ready', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_remote_id = ?', [$job]));

        $this->fake->deliver($job);
        $this->webhook('send.completed', $this->fake->sendJobs[$job]);

        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sendlog WHERE sl_type = 'MESSAGE' AND sl_muid = ?", [$muid]));
        foreach ($uuids as $uuid) {
            self::assertTrue($this->service(\App\Log\MessageLog::class)->wasSent($uuid, $muid));
        }
        $this->service(CattoMailWorker::class)->run();
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sendlog WHERE sl_type = 'MESSAGE' AND sl_muid = ?", [$muid]), 'and only once');
        self::assertSame('completed', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_remote_id = ?', [$job]));
    }
}
