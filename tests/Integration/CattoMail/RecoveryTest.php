<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\CattoMailSender;
use App\CattoMail\CattoMailWorker;
use App\CattoMail\GlobalOptOut;
use App\CattoMail\OutgoingMessage;
use App\CattoMail\OutgoingRecipient;
use App\CattoMail\SendJobAdmin;
use App\CattoMail\WebhookProcessor;
use App\Repository\CattoMailOptOutRepository;
use App\Repository\CattoMailWebhookRepository;

/**
 * Recovery after a restart or a database restore, and the administrator's
 * recovery actions: everything reconciles through stored catto-mail ids and
 * Idempotency-Keys; nothing is created, sent or applied twice, and no state
 * is ever set by hand.
 */
final class RecoveryTest extends CattoMailTestCase
{
    /** A restored database holding a sealed job whose outcome never arrived: polled by its stored id, never recreated. */
    public function testARestoredSealedJobIsReconciledByItsStoredId(): void
    {
        $sent = $this->sentCampaign(['ann@example.com']);
        $this->fake->deliver($sent['job']);
        $this->db->executeStatement("UPDATE cattomail_send_jobs SET csj_last_checked_at = '2000-01-01 00:00:00' WHERE csj_remote_id = ?", [$sent['job']]);
        $creates = count($this->fake->requestsTo('POST', '/send-jobs$'));

        self::assertSame(1, $this->service(CattoMailWorker::class)->run()['send jobs reconciled']);
        self::assertSame('completed', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_remote_id = ?', [$sent['job']]));
        self::assertCount($creates, $this->fake->requestsTo('POST', '/send-jobs$'), 'no new job');
        self::assertCount(1, $this->fake->recipients[$sent['job']], 'no second recipient');
    }

    /**
     * A job whose creation reached catto-mail but whose response was lost (or
     * a database restored to before the response): the stored key replays to
     * the same job, and the administrator's "retry sending" seals it once.
     */
    public function testRetrySendingReusesStoredKeysAndRefusesWhileStaging(): void
    {
        $id = $this->createSubscriber('ann@example.com');
        $muid = $this->createMessage('Proof');
        $sender = $this->service(CattoMailSender::class);
        $run = $sender->startRun('proof', $muid);
        $this->fake->failNext('POST /send-jobs$', 'timeout', afterEffect: true, times: 3);
        $message = new OutgoingMessage($muid, '', '', OutgoingMessage::TRANSACTIONAL, 'sender@ctnlist.test', 'Sender');
        try {
            $sender->stage($run, $message, new OutgoingRecipient('ann@example.com', $this->subscriberUuid($id), 'PROOF', 'Proof', '<p>x</p>', 'x'));
            self::fail('catto-mail answered');
        } catch (\App\CattoMail\CattoMailUnavailable) {
        }
        self::assertCount(1, $this->fake->sendJobs, 'created at catto-mail, response lost');
        $job = (int) $this->db->fetchOne('SELECT csj_id FROM cattomail_send_jobs WHERE csj_muid = ?', [$muid]);
        $admin = $this->service(SendJobAdmin::class);

        try {
            $admin->retrySending($job);
            self::fail('retried a job its run is still staging');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('still staging', $e->getMessage());
        }

        $this->db->executeStatement('UPDATE cattomail_runs SET cr_finished_at = cr_created_at WHERE cr_id = ?', [$run]);
        self::assertSame(0, $admin->retrySending($job), 'the job has no staged recipients (staging failed), so nothing is handed off');
        self::assertCount(1, $this->fake->sendJobs, 'the stored key replayed: still one job at catto-mail');
        self::assertSame('cancelled', $this->db->fetchOne('SELECT csj_status FROM cattomail_send_jobs WHERE csj_id = ?', [$job]),
            'kept (not replayed: that could create an empty job that was never there) and counted on the status page');
        self::assertSame(1, $this->service(\App\Repository\CattoMailStatusRepository::class)->counts()['jobs_empty_remote']);

        try {
            $admin->retrySending($job);
        } catch (\InvalidArgumentException) {
            // Sealed or cancelled by now: nothing left to retry.
        }
        self::assertCount(1, $this->fake->sendJobs);
    }

    public function testARefusedOptOutCanBeRetriedOnceTheCapabilityExists(): void
    {
        $id = $this->createSubscriber('ann@example.com');
        $this->fake->optOutCapability = false;
        $optOut = $this->service(GlobalOptOut::class)->request($id, $this->subscriberUuid($id), 'ann@example.com');
        self::assertSame('rejected', $optOut['cgo_status']);

        self::assertSame('rejected', $this->service(GlobalOptOut::class)->retry($optOut['cgo_uuid']), 'still refused');
        $this->fake->optOutCapability = true;
        self::assertSame('active', $this->service(GlobalOptOut::class)->retry($optOut['cgo_uuid']));
        self::assertSame('active', $this->service(GlobalOptOut::class)->retry($optOut['cgo_uuid']), 'nothing more to do');
        self::assertCount(1, $this->fake->optOuts);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_global_optouts WHERE cgo_s_id = ?', [$id]));
        self::assertNotNull($this->service(CattoMailOptOutRepository::class)->currentForSubscriber($id));
    }

    /** A failed event can be processed again; its effect is still applied once. Processed or pruned events cannot be reopened. */
    public function testReprocessingAnEventAppliesItsEffectOnce(): void
    {
        $sent = $this->sentCampaign(['ann@example.com']);
        $this->fake->deliver($sent['job'], ['ann@example.com' => 'hard_bounced']);
        $eventId = $this->eventId();
        $this->webhook('message.hard_bounced', $this->messageData($sent['job'], 'ann@example.com', 'hard_bounced', 'hard_bounce'), $eventId);
        $webhooks = $this->service(CattoMailWebhookRepository::class);
        self::assertNull($webhooks->reopen($eventId), 'processed successfully: nothing to redo');

        $this->db->executeStatement("UPDATE cattomail_webhook_events SET cwe_outcome = 'failed: simulated' WHERE cwe_event_id = ?", [$eventId]);
        $row = $webhooks->reopen($eventId);
        self::assertNotNull($row);
        $this->service(WebhookProcessor::class)->processStored($row);
        self::assertSame('status hard_bounced (already applied)', $this->db->fetchOne('SELECT cwe_outcome FROM cattomail_webhook_events WHERE cwe_event_id = ?', [$eventId]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT s_bounces FROM subscribers WHERE s_id = ?', [$sent['ids'][0]]), 'counted once');

        $this->db->executeStatement("UPDATE cattomail_webhook_events SET cwe_outcome = 'failed: again', cwe_payload = NULL WHERE cwe_event_id = ?", [$eventId]);
        self::assertNull($webhooks->reopen($eventId), 'without its body it cannot be processed');
    }
}
