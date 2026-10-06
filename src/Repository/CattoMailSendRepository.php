<?php

declare(strict_types=1);

namespace App\Repository;

use App\CattoMail\IdempotencyKey;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The catto-mail send outbox: runs, send jobs, recipient batches and
 * rendered recipients (cattomail_* tables). Idempotency keys are created
 * here with their rows, before any request uses them.
 *
 * @phpstan-type SendJob array{csj_id: int, csj_cr_id: int, csj_seq: int, csj_uuid: string, csj_idempotency_key: string,
 *     csj_remote_id: ?string, csj_muid: string, csj_list_shortcode: string, csj_message_class: string, csj_request: string,
 *     csj_status: string, csj_total: int, csj_summary: ?string, csj_error: ?string, csj_attempts: int, csj_created_at: string,
 *     csj_submitted_at: ?string, csj_completed_at: ?string, csj_last_checked_at: ?string}
 * @phpstan-type Batch array{cb_id: int, cb_csj_id: int, cb_seq: int, cb_idempotency_key: string, cb_count: int, cb_status: string, cb_remote_id: ?string}
 * @phpstan-type Recipient array{crp_id: int, crp_uuid: string, crp_csj_id: int, crp_cb_id: int, crp_s_uuid: ?string, crp_email: string,
 *     crp_muid: string, crp_list_shortcode: string, crp_type: string, crp_subject: string, crp_html: ?string, crp_text: ?string,
 *     crp_unsubscribe_url: ?string, crp_remote_message_id: ?string, crp_status: string, crp_effect_applied_at: ?string}
 */
final class CattoMailSendRepository
{
    private const JOB_COLUMNS = 'csj_id, csj_cr_id, csj_seq, csj_uuid, csj_idempotency_key, csj_remote_id, csj_muid, csj_list_shortcode,
        csj_message_class, csj_request, csj_status, csj_total, csj_summary, csj_error, csj_attempts, csj_created_at, csj_submitted_at,
        csj_completed_at, csj_last_checked_at';
    private const RECIPIENT_COLUMNS = 'crp_id, crp_uuid, crp_csj_id, crp_cb_id, crp_s_uuid, crp_email, crp_muid, crp_list_shortcode, crp_type,
        crp_subject, crp_html, crp_text, crp_unsubscribe_url, crp_remote_message_id, crp_status, crp_effect_applied_at';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function transactional(\Closure $work): mixed
    {
        return $this->db->transactional($work);
    }

    // ---- runs ----------------------------------------------------------------

    public function createRun(string $kind, string $muid): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO cattomail_runs (cr_kind, cr_muid, cr_created_at) VALUES (?, ?, ?) RETURNING cr_id',
            [$kind, $muid, $this->now()]
        );
    }

    public function finishRun(int $runId): void
    {
        $this->db->executeStatement('UPDATE cattomail_runs SET cr_finished_at = COALESCE(cr_finished_at, ?) WHERE cr_id = ?', [$this->now(), $runId]);
    }

    public function runHasRecipients(int $runId): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM cattomail_recipients r JOIN cattomail_send_jobs j ON j.csj_id = r.crp_csj_id WHERE j.csj_cr_id = ? LIMIT 1', [$runId]) !== false;
    }

    public function runKind(int $runId): string
    {
        return (string) $this->db->fetchOne('SELECT cr_kind FROM cattomail_runs WHERE cr_id = ?', [$runId]);
    }

    public function runUuid(int $runId): string
    {
        return (string) $this->db->fetchOne('SELECT cr_uuid FROM cattomail_runs WHERE cr_id = ?', [$runId]);
    }

    // ---- jobs ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $request the SendJobCreateRequest without external_reference (the job's uuid is added)
     * @return SendJob
     */
    public function createJob(int $runId, string $muid, string $list, string $class, array $request): array
    {
        $seq = (int) $this->db->fetchOne('SELECT COALESCE(MAX(csj_seq), 0) + 1 FROM cattomail_send_jobs WHERE csj_cr_id = ?', [$runId]);
        $runUuid = $this->runUuid($runId);
        $id = (int) $this->db->fetchOne(
            "INSERT INTO cattomail_send_jobs (csj_cr_id, csj_seq, csj_idempotency_key, csj_muid, csj_list_shortcode, csj_message_class, csj_request, csj_created_at)
             VALUES (?, ?, ?, ?, ?, ?, '{}', ?) RETURNING csj_id",
            [$runId, $seq, IdempotencyKey::generate(), $muid, $list, $class, $this->now()]
        );
        // The external reference ties the job to the ctnlist run: <run uuid>/<job sequence>.
        $request = ['external_reference' => $runUuid . '/' . $seq] + $request;
        $this->db->executeStatement('UPDATE cattomail_send_jobs SET csj_request = ? WHERE csj_id = ?', [json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
        return $this->job($id) ?? throw new \LogicException('Send job not stored.');
    }

    /** @return SendJob|null */
    public function job(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::JOB_COLUMNS . ' FROM cattomail_send_jobs WHERE csj_id = ?', [$id]);
        return $row === false ? null : self::hydrateJob($row);
    }

    /** @return SendJob|null */
    public function jobByRemoteId(string $remoteId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::JOB_COLUMNS . ' FROM cattomail_send_jobs WHERE csj_remote_id = ?', [strtolower($remoteId)]);
        return $row === false ? null : self::hydrateJob($row);
    }

    /** @return list<SendJob> */
    public function jobsForRun(int $runId): array
    {
        return array_map(self::hydrateJob(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::JOB_COLUMNS . ' FROM cattomail_send_jobs WHERE csj_cr_id = ? ORDER BY csj_seq', [$runId]
        ));
    }

    /** @return list<SendJob> jobs of a message, newest first */
    public function jobsForMessage(string $muid): array
    {
        return array_map(self::hydrateJob(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::JOB_COLUMNS . ' FROM cattomail_send_jobs WHERE csj_muid = ? ORDER BY csj_id DESC', [$muid]
        ));
    }

    /**
     * Jobs not yet sealed at catto-mail whose run has finished (or was
     * abandoned before $abandonedBefore): they need create/upload/submit retries.
     *
     * @return list<SendJob>
     */
    public function unsealedJobs(string $abandonedBefore, int $limit = 100): array
    {
        return array_map(self::hydrateJob(...), $this->db->fetchAllAssociative(
            "SELECT j.csj_id, j.csj_cr_id, j.csj_seq, j.csj_uuid, j.csj_idempotency_key, j.csj_remote_id, j.csj_muid, j.csj_list_shortcode,
                    j.csj_message_class, j.csj_request, j.csj_status, j.csj_total, j.csj_summary, j.csj_error, j.csj_attempts, j.csj_created_at,
                    j.csj_submitted_at, j.csj_completed_at, j.csj_last_checked_at
             FROM cattomail_send_jobs j JOIN cattomail_runs r ON r.cr_id = j.csj_cr_id
             WHERE j.csj_status IN ('open', 'ready') AND (r.cr_finished_at IS NOT NULL OR r.cr_created_at < ?)
             ORDER BY j.csj_id LIMIT " . max(1, $limit),
            [$abandonedBefore]
        ));
    }

    /**
     * Sealed jobs without a final state whose last check is older than $before.
     *
     * @return list<SendJob>
     */
    public function unresolvedJobs(string $before, int $limit = 100): array
    {
        return array_map(self::hydrateJob(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::JOB_COLUMNS . " FROM cattomail_send_jobs
             WHERE csj_status IN ('queued', 'processing', 'dispatched') AND COALESCE(csj_last_checked_at, csj_submitted_at, csj_created_at) < ?
             ORDER BY COALESCE(csj_last_checked_at, csj_submitted_at, csj_created_at) LIMIT " . max(1, $limit),
            [$before]
        ));
    }

    public function setJobRemoteId(int $jobId, string $remoteId): void
    {
        $this->db->executeStatement('UPDATE cattomail_send_jobs SET csj_remote_id = ?, csj_error = NULL WHERE csj_id = ?', [strtolower($remoteId), $jobId]);
    }

    /** Close a job to new recipients (it can now be submitted) and record its size. */
    public function closeJob(int $jobId): void
    {
        $this->db->executeStatement(
            "UPDATE cattomail_batches SET cb_count = (SELECT COUNT(*) FROM cattomail_recipients WHERE crp_cb_id = cb_id), cb_status = 'ready'
             WHERE cb_csj_id = ? AND cb_status = 'open'",
            [$jobId]
        );
        $this->db->executeStatement(
            "UPDATE cattomail_send_jobs SET csj_total = (SELECT COUNT(*) FROM cattomail_recipients WHERE crp_csj_id = csj_id),
                csj_status = CASE WHEN csj_status = 'open' THEN 'ready' ELSE csj_status END WHERE csj_id = ?",
            [$jobId]
        );
    }

    /**
     * Record catto-mail's job state, never moving a job back to an earlier
     * state (webhooks and polls can arrive late or out of order).
     *
     * @param array<string, mixed>|null $summary summary_counts
     * @return bool whether the stored state changed
     */
    public function updateJobState(int $jobId, string $status, ?array $summary, ?string $completedAt = null): bool
    {
        // One statement, compared against the row as it is now: a webhook and the worker updating the
        // same job at once can never move it backwards. catto-mail's `collecting` (and any unknown
        // value) ranks below everything, so it never replaces ctnlist's own open/ready.
        $rank = static fn(string $column): string => "CASE {$column} WHEN 'open' THEN 1 WHEN 'ready' THEN 2 WHEN 'queued' THEN 3 WHEN 'processing' THEN 4
            WHEN 'dispatched' THEN 5 WHEN 'completed' THEN 6 WHEN 'failed' THEN 6 WHEN 'cancelled' THEN 6 ELSE 0 END";
        $terminal = in_array($status, ['completed', 'failed', 'cancelled'], true);
        $row = $this->db->fetchAssociative(
            'UPDATE cattomail_send_jobs SET
                csj_status = CASE WHEN ' . $rank(':status') . ' > ' . $rank('csj_status') . ' THEN :status ELSE csj_status END,
                csj_completed_at = CASE WHEN :terminal AND ' . $rank(':status') . ' > ' . $rank('csj_status') . ' THEN COALESCE(csj_completed_at, :completed) ELSE csj_completed_at END,
                csj_summary = COALESCE(:summary, csj_summary), csj_last_checked_at = :now
             WHERE csj_id = :id RETURNING csj_status',
            ['status' => $status, 'terminal' => $terminal ? 'true' : 'false', 'completed' => $completedAt ?? $this->now(),
                'summary' => $summary === null ? null : json_encode($summary), 'now' => $this->now(), 'id' => $jobId]
        );
        return $row !== false && $row['csj_status'] === $status;
    }

    public function markJobSubmitted(int $jobId, string $remoteStatus): void
    {
        $this->db->executeStatement(
            "UPDATE cattomail_send_jobs SET csj_status = CASE WHEN csj_status IN ('open', 'ready') THEN ? ELSE csj_status END,
                csj_submitted_at = COALESCE(csj_submitted_at, ?), csj_last_checked_at = ?, csj_error = NULL WHERE csj_id = ?",
            [in_array($remoteStatus, ['queued', 'processing', 'dispatched', 'completed'], true) ? $remoteStatus : 'queued', $this->now(), $this->now(), $jobId]
        );
    }

    public function failJob(int $jobId, string $error): void
    {
        $this->db->transactional(function () use ($jobId, $error): void {
            $this->db->executeStatement(
                "UPDATE cattomail_send_jobs SET csj_status = 'failed', csj_error = ?, csj_completed_at = COALESCE(csj_completed_at, ?) WHERE csj_id = ?",
                [mb_substr($error, 0, 2000), $this->now(), $jobId]
            );
            $this->db->executeStatement("UPDATE cattomail_recipients SET crp_status = 'not_sent', crp_updated_at = ? WHERE crp_csj_id = ? AND crp_status = 'staged'", [$this->now(), $jobId]);
        });
    }

    /** A retryable failure: keep the job for the next attempt. */
    public function noteJobAttempt(int $jobId, string $error): void
    {
        $this->db->executeStatement('UPDATE cattomail_send_jobs SET csj_attempts = csj_attempts + 1, csj_error = ? WHERE csj_id = ?', [mb_substr($error, 0, 2000), $jobId]);
    }

    public function deleteEmptyJob(int $jobId): void
    {
        $this->db->executeStatement('DELETE FROM cattomail_send_jobs WHERE csj_id = ? AND csj_remote_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM cattomail_recipients WHERE crp_csj_id = csj_id)', [$jobId]);
    }

    // ---- batches and recipients ----------------------------------------------

    /** Open the job's next recipient batch, with its Idempotency-Key. */
    public function newBatch(int $jobId): int
    {
        $seq = (int) $this->db->fetchOne('SELECT COALESCE(MAX(cb_seq), 0) + 1 FROM cattomail_batches WHERE cb_csj_id = ?', [$jobId]);
        return (int) $this->db->fetchOne(
            'INSERT INTO cattomail_batches (cb_csj_id, cb_seq, cb_idempotency_key) VALUES (?, ?, ?) RETURNING cb_id',
            [$jobId, $seq, IdempotencyKey::generate()]
        );
    }

    /**
     * Store one rendered recipient in a batch (inside the caller's
     * transaction). Batch and job counts are derived from these rows when the
     * batch or job is closed, so no counter row is updated per recipient.
     *
     * @param array{email: string, subscriber_uuid: ?string, muid: string, list: string, type: string, subject: string,
     *     html: string, text: string, unsubscribe_url: ?string} $recipient
     */
    public function addRecipient(int $jobId, int $batchId, array $recipient): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO cattomail_recipients (crp_csj_id, crp_cb_id, crp_s_uuid, crp_email, crp_muid, crp_list_shortcode, crp_type,
                crp_subject, crp_html, crp_text, crp_unsubscribe_url, crp_updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING crp_id',
            [$jobId, $batchId, $recipient['subscriber_uuid'], $recipient['email'], $recipient['muid'], strtoupper($recipient['list']), $recipient['type'],
                $recipient['subject'], $recipient['html'], $recipient['text'], $recipient['unsubscribe_url'], $this->now()]
        );
    }

    /** No more recipients go into this batch: record its size and make it uploadable. */
    public function closeBatch(int $batchId): void
    {
        $this->db->executeStatement(
            "UPDATE cattomail_batches SET cb_count = (SELECT COUNT(*) FROM cattomail_recipients WHERE crp_cb_id = cb_id),
                cb_status = CASE WHEN cb_status = 'open' THEN 'ready' ELSE cb_status END WHERE cb_id = ?",
            [$batchId]
        );
    }

    /** @return Batch|null */
    public function batch(int $batchId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT cb_id, cb_csj_id, cb_seq, cb_idempotency_key, cb_count, cb_status, cb_remote_id FROM cattomail_batches WHERE cb_id = ?', [$batchId]);
        return $row === false ? null : self::hydrateBatch($row);
    }

    /** @return list<Batch> batches of the job not yet accepted by catto-mail, in order */
    public function pendingBatches(int $jobId): array
    {
        return array_map(self::hydrateBatch(...), $this->db->fetchAllAssociative(
            "SELECT cb_id, cb_csj_id, cb_seq, cb_idempotency_key, cb_count, cb_status, cb_remote_id FROM cattomail_batches
             WHERE cb_csj_id = ? AND cb_status <> 'accepted' AND EXISTS (SELECT 1 FROM cattomail_recipients WHERE crp_cb_id = cb_id) ORDER BY cb_seq",
            [$jobId]
        ));
    }

    /** @return list<Recipient> */
    public function batchRecipients(int $batchId): array
    {
        return array_map(self::hydrateRecipient(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::RECIPIENT_COLUMNS . ' FROM cattomail_recipients WHERE crp_cb_id = ? ORDER BY crp_id', [$batchId]
        ));
    }

    /** catto-mail accepted the batch: its content is no longer needed for a replay. */
    public function markBatchAccepted(int $batchId, string $remoteBatchId): void
    {
        $this->db->transactional(function () use ($batchId, $remoteBatchId): void {
            $this->db->executeStatement("UPDATE cattomail_batches SET cb_status = 'accepted', cb_remote_id = ?, cb_accepted_at = ? WHERE cb_id = ?",
                [$remoteBatchId !== '' ? strtolower($remoteBatchId) : null, $this->now(), $batchId]);
            $this->db->executeStatement('UPDATE cattomail_recipients SET crp_html = NULL, crp_text = NULL WHERE crp_cb_id = ?', [$batchId]);
        });
    }

    /**
     * The job was sealed: its recipients are handed off. Returns those that
     * were still staged (each is logged exactly once by the caller).
     *
     * @return list<Recipient>
     */
    public function handOff(int $jobId): array
    {
        $rows = $this->db->fetchAllAssociative(
            "UPDATE cattomail_recipients SET crp_status = 'handed_off', crp_updated_at = ? WHERE crp_csj_id = ? AND crp_status = 'staged'
             RETURNING " . self::RECIPIENT_COLUMNS,
            [$this->now(), $jobId]
        );
        return array_map(self::hydrateRecipient(...), $rows);
    }

    /** @return Recipient|null */
    public function recipientByUuid(string $uuid): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT ' . self::RECIPIENT_COLUMNS . ' FROM cattomail_recipients WHERE crp_uuid = ?', [strtolower($uuid)]);
        return $row === false ? null : self::hydrateRecipient($row);
    }

    /**
     * Record catto-mail's message state for a recipient. A terminal state is
     * never replaced by a non-terminal one, and a hard bounce or complaint is
     * never replaced at all (late or repeated events cannot regress it).
     */
    public function updateRecipientStatus(int $recipientId, string $status, ?string $remoteMessageId, ?string $resolvedAt): void
    {
        $this->db->executeStatement(
            "UPDATE cattomail_recipients SET
                crp_status = CASE
                    WHEN crp_status IN ('hard_bounced', 'complained') THEN crp_status
                    WHEN crp_status IN ('remote_accepted', 'soft_bounced', 'failed', 'suppressed', 'outcome_unknown')
                         AND :status NOT IN ('hard_bounced', 'complained', 'remote_accepted', 'soft_bounced', 'failed', 'suppressed', 'outcome_unknown') THEN crp_status
                    ELSE :status END,
                crp_remote_message_id = COALESCE(crp_remote_message_id, :message),
                crp_resolved_at = COALESCE(crp_resolved_at, :resolved),
                crp_updated_at = :now
             WHERE crp_id = :id",
            ['status' => $status, 'message' => $remoteMessageId !== null ? strtolower($remoteMessageId) : null, 'resolved' => $resolvedAt, 'now' => $this->now(), 'id' => $recipientId]
        );
    }

    /**
     * Claim the once-only business effect of a hard bounce or complaint for
     * this recipient. A complaint after a hard bounce is claimed once more
     * (it adds the unsubscribe); nothing is ever applied twice.
     */
    public function claimEffect(int $recipientId, string $kind): bool
    {
        return (int) $this->db->executeStatement(
            "UPDATE cattomail_recipients SET crp_effect = :kind, crp_effect_applied_at = :now
             WHERE crp_id = :id AND (crp_effect IS NULL OR (crp_effect = 'hard_bounced' AND :kind = 'complained'))",
            ['kind' => $kind, 'now' => $this->now(), 'id' => $recipientId]
        ) === 1;
    }

    /** @return array<string, int> recipients of the message per status */
    public function statusCounts(string $muid): array
    {
        $counts = [];
        foreach ($this->db->fetchAllAssociative('SELECT crp_status, COUNT(*) AS n FROM cattomail_recipients WHERE crp_muid = ? GROUP BY crp_status', [$muid]) as $row) {
            $counts[(string) $row['crp_status']] = (int) $row['n'];
        }
        return $counts;
    }

    /** @return list<Recipient> */
    public function recipientsForJob(int $jobId, int $offset, int $limit, string $status = ''): array
    {
        return array_map(self::hydrateRecipient(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::RECIPIENT_COLUMNS . ' FROM cattomail_recipients WHERE crp_csj_id = :job'
            . ($status !== '' ? ' AND crp_status = :status' : '') . ' ORDER BY crp_id LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $status !== '' ? ['job' => $jobId, 'status' => $status] : ['job' => $jobId]
        ));
    }

    public function recipientCountForJob(int $jobId, string $status = ''): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM cattomail_recipients WHERE crp_csj_id = :job' . ($status !== '' ? ' AND crp_status = :status' : ''),
            $status !== '' ? ['job' => $jobId, 'status' => $status] : ['job' => $jobId]
        );
    }

    /** @return list<array{crp_id: int, crp_s_uuid: string, crp_email: string, crp_list_shortcode: string}> not-sent subscriber recipients of a failed job */
    public function notSentRecipients(int $jobId): array
    {
        return array_map(static fn(array $r): array => [
            'crp_id' => (int) $r['crp_id'], 'crp_s_uuid' => (string) $r['crp_s_uuid'], 'crp_email' => (string) $r['crp_email'], 'crp_list_shortcode' => (string) $r['crp_list_shortcode'],
        ], $this->db->fetchAllAssociative(
            "SELECT crp_id, crp_s_uuid, crp_email, crp_list_shortcode FROM cattomail_recipients WHERE crp_csj_id = ? AND crp_status = 'not_sent' AND crp_s_uuid IS NOT NULL ORDER BY crp_id",
            [$jobId]
        ));
    }

    public function deleteRecipients(int $jobId, string $status): int
    {
        return (int) $this->db->executeStatement('DELETE FROM cattomail_recipients WHERE crp_csj_id = ? AND crp_status = ?', [$jobId, $status]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     * @return SendJob
     */
    private static function hydrateJob(array $row): array
    {
        return [
            'csj_id' => (int) $row['csj_id'], 'csj_cr_id' => (int) $row['csj_cr_id'], 'csj_seq' => (int) $row['csj_seq'], 'csj_uuid' => (string) $row['csj_uuid'],
            'csj_idempotency_key' => (string) $row['csj_idempotency_key'], 'csj_remote_id' => $row['csj_remote_id'] === null ? null : (string) $row['csj_remote_id'],
            'csj_muid' => (string) $row['csj_muid'], 'csj_list_shortcode' => (string) $row['csj_list_shortcode'], 'csj_message_class' => (string) $row['csj_message_class'],
            'csj_request' => (string) $row['csj_request'], 'csj_status' => (string) $row['csj_status'], 'csj_total' => (int) $row['csj_total'],
            'csj_summary' => $row['csj_summary'] === null ? null : (string) $row['csj_summary'], 'csj_error' => $row['csj_error'] === null ? null : (string) $row['csj_error'],
            'csj_attempts' => (int) $row['csj_attempts'], 'csj_created_at' => (string) $row['csj_created_at'],
            'csj_submitted_at' => $row['csj_submitted_at'] === null ? null : (string) $row['csj_submitted_at'],
            'csj_completed_at' => $row['csj_completed_at'] === null ? null : (string) $row['csj_completed_at'],
            'csj_last_checked_at' => $row['csj_last_checked_at'] === null ? null : (string) $row['csj_last_checked_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return Batch
     */
    private static function hydrateBatch(array $row): array
    {
        return [
            'cb_id' => (int) $row['cb_id'], 'cb_csj_id' => (int) $row['cb_csj_id'], 'cb_seq' => (int) $row['cb_seq'],
            'cb_idempotency_key' => (string) $row['cb_idempotency_key'], 'cb_count' => (int) $row['cb_count'], 'cb_status' => (string) $row['cb_status'],
            'cb_remote_id' => $row['cb_remote_id'] === null ? null : (string) $row['cb_remote_id'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return Recipient
     */
    private static function hydrateRecipient(array $row): array
    {
        return [
            'crp_id' => (int) $row['crp_id'], 'crp_uuid' => (string) $row['crp_uuid'], 'crp_csj_id' => (int) $row['crp_csj_id'], 'crp_cb_id' => (int) $row['crp_cb_id'],
            'crp_s_uuid' => $row['crp_s_uuid'] === null ? null : (string) $row['crp_s_uuid'], 'crp_email' => (string) $row['crp_email'],
            'crp_muid' => (string) $row['crp_muid'], 'crp_list_shortcode' => (string) $row['crp_list_shortcode'], 'crp_type' => (string) $row['crp_type'],
            'crp_subject' => (string) $row['crp_subject'], 'crp_html' => $row['crp_html'] === null ? null : (string) $row['crp_html'],
            'crp_text' => $row['crp_text'] === null ? null : (string) $row['crp_text'],
            'crp_unsubscribe_url' => $row['crp_unsubscribe_url'] === null ? null : (string) $row['crp_unsubscribe_url'],
            'crp_remote_message_id' => $row['crp_remote_message_id'] === null ? null : (string) $row['crp_remote_message_id'],
            'crp_status' => (string) $row['crp_status'], 'crp_effect_applied_at' => $row['crp_effect_applied_at'] === null ? null : (string) $row['crp_effect_applied_at'],
        ];
    }
}
