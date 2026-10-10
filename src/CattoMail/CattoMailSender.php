<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Log\MessageLog;
use App\Log\SendLog;
use App\Repository\CattoMailSendRepository;
use Psr\Log\LoggerInterface;

/**
 * The campaign transport: rendered recipients go to catto-mail send jobs.
 *
 * A run (one queue run, proof, resend or forward) stages recipients in the
 * outbox (cattomail_recipients) in the same database transaction as the
 * caller's own change (e.g. removing the queue row), so nothing is lost or
 * duplicated if the process stops. Recipients of one message and list share
 * a send job of at most 10,000 recipients (a larger run gets more jobs), and
 * are uploaded in batches of at most 500. The job is created, each batch
 * uploaded and the job submitted with the Idempotency-Key stored with its
 * row, so retries (in this run or by the worker) never create duplicates.
 * When catto-mail has sealed a job its recipients are handed off: each gets
 * its Send Log row and, for subscribers, the smlog sent mark.
 *
 * @phpstan-import-type SendJob from CattoMailSendRepository
 * @phpstan-import-type Batch from CattoMailSendRepository
 */
final class CattoMailSender
{
    /** The JSON envelope of a batch upload, {"recipients":[]}. */
    private const BATCH_ENVELOPE_BYTES = 17;
    /** Stands in for a recipient's external reference (a UUID, assigned on insert) when sizing it. */
    private const UUID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

    private int $batchMax = CattoMailConfig::MAX_BATCH_RECIPIENTS;
    private int $jobMax = CattoMailConfig::MAX_JOB_RECIPIENTS;

    /**
     * The run's open jobs: run id => (OutgoingMessage key => job id, its open
     * batch and the counts and JSON size staged so far in this process).
     *
     * @var array<int, array<string, array{job: int, batch: int, batch_count: int, batch_bytes: int, total: int}>>
     */
    private array $openJobs = [];

    public function __construct(
        private readonly CattoMailSendRepository $outbox,
        private readonly CattoMailClient $client,
        private readonly CattoMailConfig $config,
        private readonly UnsubscribeLinks $links,
        private readonly SendLog $sendLog,
        private readonly MessageLog $messageLog,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Lower the batch and job sizes (tests); they can never exceed catto-mail's limits. */
    public function useLimits(int $batchMax, int $jobMax): void
    {
        $this->batchMax = max(1, min(CattoMailConfig::MAX_BATCH_RECIPIENTS, $batchMax));
        $this->jobMax = max(1, min(CattoMailConfig::MAX_JOB_RECIPIENTS, $jobMax));
    }

    /** Why nothing can be sent right now (configuration), or null. */
    public function problem(): ?string
    {
        return $this->config->problem();
    }

    /** @param string $kind campaign, proof, resend or forward */
    public function startRun(string $kind, string $muid): int
    {
        $runId = $this->outbox->createRun($kind, $muid);
        $this->openJobs[$runId] = [];
        return $runId;
    }

    /**
     * Stage one rendered recipient. $alsoInTransaction runs in the same
     * database transaction (for example removing the queue row). Uploads the
     * batch when it is full and seals the job when it reaches 10,000.
     *
     * @throws CattoMailException when catto-mail refuses or cannot be reached (already staged work is kept)
     */
    public function stage(int $runId, OutgoingMessage $message, OutgoingRecipient $recipient, ?\Closure $alsoInTransaction = null): void
    {
        $key = $message->key();
        $unsubscribe = $message->messageClass === OutgoingMessage::SUBSCRIPTION && $recipient->subscriberUuid !== null
            ? $this->links->url($recipient->subscriberUuid, $message->listShortcode, $message->muid)
            : null;
        $fields = [
            'email' => trim($recipient->email),
            'subscriber_uuid' => $recipient->subscriberUuid,
            'muid' => $message->muid,
            'list' => $message->listShortcode,
            'type' => $recipient->type,
            // catto-mail requires a subject; the content is otherwise sent exactly as rendered.
            'subject' => trim($recipient->subject) !== '' ? $recipient->subject : '(no subject)',
            'html' => $recipient->html,
            'text' => $recipient->text,
            'unsubscribe_url' => $unsubscribe,
        ];
        // Batches are cut by size as well as by count: each recipient carries its own rendered copy,
        // and catto-mail refuses a request over 10 MiB.
        $bytes = CattoMailClient::jsonSize(self::payload(self::UUID_PLACEHOLDER, $fields['email'], $fields['subject'], $fields['html'], $fields['text'], $unsubscribe)) + 1;
        if (self::BATCH_ENVELOPE_BYTES + $bytes > CattoMailConfig::MAX_BATCH_BYTES) {
            throw new CattoMailRejected(sprintf('Message %s is too large to send: one copy is %.1f MB as uploaded to catto-mail, over the %d MB limit per upload. '
                . 'Make it smaller, for example by linking images instead of embedding them.', $message->muid, $bytes / 1048576, intdiv(CattoMailConfig::MAX_BATCH_BYTES, 1048576)), 0, 'content-too-large');
        }

        // A run the worker took for abandoned has its jobs sealed: a recipient never joins a sealed
        // job (it would never be uploaded); the run goes on in a new job.
        do {
            $open = $this->openJob($runId, $message);
            if ($open['batch_count'] > 0 && self::BATCH_ENVELOPE_BYTES + $open['batch_bytes'] + $bytes > CattoMailConfig::MAX_BATCH_BYTES) {
                $open = $this->nextBatch($runId, $key, $open);
            }
            $added = $this->outbox->transactional(function () use ($open, $fields, $alsoInTransaction): bool {
                if (!$this->outbox->lockOpenJob($open['job'])) {
                    return false;
                }
                $this->outbox->addRecipient($open['job'], $open['batch'], $fields);
                if ($alsoInTransaction !== null) {
                    $alsoInTransaction();
                }
                return true;
            });
            if (!$added) {
                unset($this->openJobs[$runId][$key]);
            }
        } while (!$added);
        $open['batch_count']++;
        $open['batch_bytes'] += $bytes;
        $open['total']++;
        $this->openJobs[$runId][$key] = $open;

        if ($open['total'] >= $this->jobMax) {
            // The job is full: seal it; the next recipient opens the next job of the run.
            unset($this->openJobs[$runId][$key]);
            $this->outbox->closeJob($open['job']);
            $this->flushJob($open['job']);
        } elseif ($open['batch_count'] >= $this->batchMax) {
            $this->nextBatch($runId, $key, $open);
        }
    }

    /**
     * Close the open batch, upload it and open the job's next one.
     *
     * @param array{job: int, batch: int, batch_count: int, batch_bytes: int, total: int} $open
     * @return array{job: int, batch: int, batch_count: int, batch_bytes: int, total: int}
     */
    private function nextBatch(int $runId, string $key, array $open): array
    {
        $this->outbox->closeBatch($open['batch']);
        $next = ['batch' => $this->outbox->newBatch($open['job']), 'batch_count' => 0, 'batch_bytes' => 0] + $open;
        $this->openJobs[$runId][$key] = $next;
        $job = $this->outbox->job($open['job']);
        $batch = $this->outbox->batch($open['batch']);
        if ($job !== null && $batch !== null) {
            $this->upload($job, $batch);
        }
        return $next;
    }

    /**
     * One recipient as catto-mail receives it in a batch.
     *
     * @return array<string, string>
     */
    private static function payload(string $reference, string $email, string $subject, ?string $html, ?string $text, ?string $unsubscribeUrl): array
    {
        $recipient = ['external_recipient_reference' => $reference, 'email_address' => $email, 'subject' => $subject];
        if (trim((string) $html) !== '') {
            $recipient['html_body'] = (string) $html;
        }
        if (trim((string) $text) !== '') {
            $recipient['text_body'] = (string) $text;
        }
        if ($unsubscribeUrl !== null) {
            $recipient['unsubscribe_url'] = $unsubscribeUrl;
        }
        return $recipient;
    }

    /**
     * Close the run: upload what is left and submit each of its jobs.
     * Failures do not stop the other jobs; retryable ones stay for the worker.
     */
    public function finishRun(int $runId, int $staged): SendOutcome
    {
        unset($this->openJobs[$runId]);
        $this->outbox->finishRun($runId);
        $handedOff = 0;
        $problem = null;
        $retryable = true;
        foreach ($this->outbox->jobsForRun($runId) as $job) {
            if (!in_array($job['csj_status'], ['open', 'ready'], true)) {
                $handedOff += $job['csj_status'] === 'failed' ? 0 : $job['csj_total'];
                continue;
            }
            $this->outbox->closeJob($job['csj_id']);
            try {
                $handedOff += $this->flushJob($job['csj_id']);
            } catch (CattoMailException $e) {
                $problem ??= $e->getMessage();
                $retryable = $retryable && $e instanceof CattoMailUnavailable;
            }
        }
        return SendOutcome::of($staged, $handedOff, $problem, $retryable);
    }

    /** A whole run for one recipient (proof, resend, forward copy). */
    public function sendOne(string $kind, OutgoingMessage $message, OutgoingRecipient $recipient): SendOutcome
    {
        $problem = $this->problem();
        if ($problem !== null) {
            return SendOutcome::of(0, 0, $problem, false);
        }
        $runId = $this->startRun($kind, $message->muid);
        try {
            $this->stage($runId, $message, $recipient);
        } catch (CattoMailException $e) {
            $this->outbox->finishRun($runId);
            return SendOutcome::of(0, 0, $e->getMessage(), $e instanceof CattoMailUnavailable && $this->hasStaged($runId));
        }
        return $this->finishRun($runId, 1);
    }

    /**
     * Bring a closed job to catto-mail: create it if needed, upload the
     * batches not yet accepted and submit it. Safe to repeat.
     *
     * @return int recipients handed off by this call
     * @throws CattoMailException
     */
    public function flushJob(int $jobId): int
    {
        $job = $this->outbox->job($jobId);
        if ($job === null || !in_array($job['csj_status'], ['open', 'ready'], true)) {
            return 0;
        }
        if ($job['csj_status'] === 'ready' && $job['csj_total'] === 0) {
            if ($job['csj_remote_id'] === null && $job['csj_attempts'] === 0) {
                $this->outbox->deleteEmptyJob($jobId);
            } else {
                // Created at catto-mail, or a create was attempted whose response was lost (replaying
                // its key could create an empty job that was never there, so it is not replayed):
                // kept as cancelled, so the status page counts it.
                // catto-mail offers no cancel; the empty job stays collecting there and sends nothing.
                $this->outbox->updateJobState($jobId, 'cancelled', null);
            }
            return 0;
        }
        $job = $this->ensureRemote($job);
        foreach ($this->outbox->pendingBatches($jobId) as $batch) {
            $this->upload($job, $batch);
        }
        if ($job['csj_status'] === 'open') {
            return 0;
        }
        return $this->submit($job);
    }

    private function hasStaged(int $runId): bool
    {
        return $this->outbox->runHasRecipients($runId);
    }

    /**
     * The run's open job for this message/list/sender, creating it (locally
     * and at catto-mail) with its first batch when needed.
     *
     * @return array{job: int, batch: int, batch_count: int, batch_bytes: int, total: int}
     */
    private function openJob(int $runId, OutgoingMessage $message): array
    {
        $key = $message->key();
        if (isset($this->openJobs[$runId][$key])) {
            return $this->openJobs[$runId][$key];
        }
        $problem = $this->config->problem();
        if ($problem !== null) {
            throw new CattoMailRejected($problem, 0, 'not-configured');
        }
        $request = [
            'message_class' => $message->messageClass,
            'sender_identity' => array_filter(['email' => trim($message->senderEmail), 'name' => trim($message->senderName)], static fn(string $v): bool => $v !== ''),
            'tracking' => ['opens' => $this->config->trackOpens, 'clicks' => $this->config->trackClicks],
        ];
        if ($message->messageClass === OutgoingMessage::SUBSCRIPTION) {
            if (!$this->links->isUsable()) {
                throw new CattoMailRejected('Subscription mail needs an https APP_BASE_URL: catto-mail only accepts https unsubscribe links.', 0, 'unsubscribe-url');
            }
            $request['list_id'] = $message->listId;
        }
        $job = $this->outbox->createJob($runId, $message->muid, $message->listShortcode, $message->messageClass, $request);
        $open = ['job' => $job['csj_id'], 'batch' => $this->outbox->newBatch($job['csj_id']), 'batch_count' => 0, 'batch_bytes' => 0, 'total' => 0];
        $this->openJobs[$runId][$key] = $open;
        $this->ensureRemote($job);
        return $open;
    }

    /**
     * @param SendJob $job
     * @return SendJob
     */
    private function ensureRemote(array $job): array
    {
        if ($job['csj_remote_id'] !== null) {
            return $job;
        }
        /** @var array<string, mixed> $request */
        $request = json_decode($job['csj_request'], true, 32, JSON_THROW_ON_ERROR);
        try {
            $remote = $this->client->createSendJob($job['csj_idempotency_key'], $request);
        } catch (CattoMailException $e) {
            $this->jobFailure($job, $e);
            throw $e;
        }
        $remoteId = (string) ($remote['id'] ?? '');
        $this->outbox->setJobRemoteId($job['csj_id'], $remoteId);
        $job['csj_remote_id'] = $remoteId;
        return $job;
    }

    /**
     * @param SendJob $job
     * @param Batch $batch
     */
    private function upload(array $job, array $batch): void
    {
        if ($batch['cb_status'] === 'accepted') {
            return;
        }
        $job = $this->ensureRemote($job);
        $recipients = [];
        foreach ($this->outbox->batchRecipients($batch['cb_id']) as $row) {
            $recipients[] = self::payload($row['crp_uuid'], $row['crp_email'], $row['crp_subject'], $row['crp_html'], $row['crp_text'], $row['crp_unsubscribe_url']);
        }
        if ($recipients === []) {
            return;
        }
        try {
            $result = $this->client->addRecipients((string) $job['csj_remote_id'], $batch['cb_idempotency_key'], $recipients);
        } catch (CattoMailException $e) {
            $this->jobFailure($job, $e);
            throw $e;
        }
        $this->outbox->markBatchAccepted($batch['cb_id'], (string) ($result['batch_id'] ?? ''));
    }

    /**
     * @param SendJob $job
     * @return int recipients handed off
     */
    private function submit(array $job): int
    {
        try {
            $remote = $this->client->submitSendJob((string) $job['csj_remote_id']);
        } catch (CattoMailException $e) {
            $this->jobFailure($job, $e);
            throw $e;
        }
        return $this->recordSubmission($job, (string) ($remote['status'] ?? 'queued'));
    }

    /**
     * catto-mail has sealed the job: its recipients are handed off (Send Log, and smlog for
     * subscribers). Also used by SendJobSync when catto-mail reports a job sealed whose submission
     * ctnlist never recorded (the process stopped between catto-mail's answer and this transaction).
     *
     * @param SendJob $job
     * @return int recipients handed off
     */
    public function recordSubmission(array $job, string $remoteStatus): int
    {
        return $this->outbox->transactional(function () use ($job, $remoteStatus): int {
            $this->outbox->markJobSubmitted($job['csj_id'], $remoteStatus);
            $recipients = $this->outbox->handOff($job['csj_id']);
            foreach ($recipients as $recipient) {
                // The handoff to catto-mail is this delivery's successful handoff (invariant: Send Log).
                $this->sendLog->record($recipient['crp_muid'], $recipient['crp_type'], $recipient['crp_email'], $recipient['crp_list_shortcode'],
                    mb_substr($recipient['crp_subject'], 0, 200), $recipient['crp_s_uuid']);
                if ($recipient['crp_s_uuid'] !== null) {
                    $this->messageLog->markSent($recipient['crp_s_uuid'], $recipient['crp_muid'], $recipient['crp_list_shortcode']);
                }
            }
            return count($recipients);
        });
    }

    /** @param SendJob $job */
    private function jobFailure(array $job, CattoMailException $e): void
    {
        if ($e instanceof CattoMailUnavailable) {
            $this->outbox->noteJobAttempt($job['csj_id'], $e->getMessage());
            return;
        }
        $this->logger->error('catto-mail refused send job {job}: {error}', ['job' => $job['csj_uuid'], 'error' => $e->getMessage()]);
        $this->outbox->failJob($job['csj_id'], $e->getMessage());
    }
}
