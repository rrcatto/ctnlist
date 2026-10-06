<?php

declare(strict_types=1);

namespace App\Repository;

use App\CattoMail\IdempotencyKey;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * catto-mail address validation jobs and their per-address results
 * (cattomail_validation_*). external_address_reference is the subscriber
 * UUID; results are stored exactly as catto-mail classified them.
 *
 * @phpstan-type ValidationJob array{cvj_id: int, cvj_uuid: string, cvj_idempotency_key: string, cvj_remote_id: ?string, cvj_scope: string,
 *     cvj_status: string, cvj_total: int, cvj_processed: int, cvj_counts: array<string, int>, cvj_results_complete: bool, cvj_error: ?string,
 *     cvj_created_at: string, cvj_completed_at: ?string, cvj_last_checked_at: ?string}
 */
final class CattoMailValidationRepository
{
    private const JOB_COLUMNS = 'cvj_id, cvj_uuid, cvj_idempotency_key, cvj_remote_id, cvj_scope, cvj_status, cvj_total, cvj_processed, cvj_counts,
        cvj_results_complete, cvj_error, cvj_created_at, cvj_completed_at, cvj_last_checked_at';

    /** Result columns, in catto-mail's field order. */
    public const RESULT_FIELDS = [
        'normalized_address' => 'cva_normalized_address', 'syntax_status' => 'cva_syntax_status', 'domain_status' => 'cva_domain_status',
        'smtp_status' => 'cva_smtp_status', 'is_role' => 'cva_is_role', 'is_disposable' => 'cva_is_disposable',
        'is_catch_all_or_accept_all' => 'cva_is_catch_all', 'is_domain_typo_suspected' => 'cva_is_typo_suspected',
        'suggested_address' => 'cva_suggested_address', 'suggestion_reason_code' => 'cva_suggestion_reason',
        'suggestion_confidence' => 'cva_suggestion_confidence', 'overall_classification' => 'cva_classification', 'confidence' => 'cva_confidence',
        'diagnostic_code' => 'cva_diagnostic_code', 'diagnostic_text' => 'cva_diagnostic_text',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Store a job and its addresses (submission order) before anything is sent.
     *
     * @param list<array{s_uuid: string, s_email: string}> $subscribers
     */
    public function create(string $scope, array $subscribers, ?int $createdBy): int
    {
        return $this->db->transactional(function () use ($scope, $subscribers, $createdBy): int {
            $jobId = (int) $this->db->fetchOne(
                'INSERT INTO cattomail_validation_jobs (cvj_idempotency_key, cvj_scope, cvj_total, cvj_created_by_s_id, cvj_created_at)
                 VALUES (?, ?, ?, ?, ?) RETURNING cvj_id',
                [IdempotencyKey::generate(), mb_substr($scope, 0, 200), count($subscribers), $createdBy, $this->now()]
            );
            foreach ($subscribers as $subscriber) {
                $this->db->executeStatement(
                    'INSERT INTO cattomail_validation_addresses (cva_cvj_id, cva_s_uuid, cva_address) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                    [$jobId, $subscriber['s_uuid'], $subscriber['s_email']]
                );
            }
            return $jobId;
        });
    }

    /** @return ValidationJob|null */
    public function job(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::JOB_COLUMNS . ' FROM cattomail_validation_jobs WHERE cvj_id = ?', [$id]);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return ValidationJob|null by catto-mail id, else by our external reference */
    public function jobByRemote(string $remoteId, string $externalReference = ''): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::JOB_COLUMNS . ' FROM cattomail_validation_jobs WHERE cvj_remote_id = ?', [strtolower($remoteId)]);
        if ($row === false && preg_match('/^[0-9a-f-]{36}$/i', $externalReference) === 1) {
            $row = $this->db->fetchAssociative('SELECT ' . self::JOB_COLUMNS . ' FROM cattomail_validation_jobs WHERE cvj_uuid = ?', [strtolower($externalReference)]);
        }
        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<ValidationJob> newest first */
    public function recent(int $limit = 50): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::JOB_COLUMNS . ' FROM cattomail_validation_jobs ORDER BY cvj_id DESC LIMIT ' . max(1, $limit)
        ));
    }

    /** @return list<ValidationJob> jobs not yet accepted by catto-mail */
    public function unsubmitted(int $limit = 20): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAllAssociative(
            "SELECT " . self::JOB_COLUMNS . " FROM cattomail_validation_jobs WHERE cvj_status = 'pending' ORDER BY cvj_id LIMIT " . max(1, $limit)
        ));
    }

    /** @return list<ValidationJob> jobs without a final state (or without all results) last checked before $before */
    public function unresolved(string $before, int $limit = 20): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAllAssociative(
            "SELECT " . self::JOB_COLUMNS . " FROM cattomail_validation_jobs
             WHERE (cvj_status IN ('queued', 'processing') OR (cvj_status = 'completed' AND NOT cvj_results_complete))
               AND COALESCE(cvj_last_checked_at, cvj_created_at) < ?
             ORDER BY COALESCE(cvj_last_checked_at, cvj_created_at) LIMIT " . max(1, $limit),
            [$before]
        ));
    }

    /** @return list<array{address: string, external_address_reference: string}> the exact create body, in submission order */
    public function submission(int $jobId): array
    {
        return array_map(static fn(array $r): array => ['address' => (string) $r['cva_address'], 'external_address_reference' => (string) $r['cva_s_uuid']],
            $this->db->fetchAllAssociative('SELECT cva_address, cva_s_uuid FROM cattomail_validation_addresses WHERE cva_cvj_id = ? ORDER BY cva_id', [$jobId]));
    }

    public function setRemote(int $jobId, string $remoteId): void
    {
        $this->db->executeStatement("UPDATE cattomail_validation_jobs SET cvj_remote_id = ?, cvj_status = CASE WHEN cvj_status = 'pending' THEN 'queued' ELSE cvj_status END,
            cvj_error = NULL, cvj_updated_at = ?, cvj_last_checked_at = ? WHERE cvj_id = ?", [strtolower($remoteId), $this->now(), $this->now(), $jobId]);
    }

    /**
     * catto-mail's job state; never moves a job back to an earlier state.
     *
     * @param array<string, int> $counts
     */
    public function updateState(int $jobId, string $status, int $processed, array $counts, ?string $completedAt): void
    {
        $job = $this->job($jobId);
        if ($job === null) {
            return;
        }
        $order = ['pending' => 0, 'queued' => 1, 'processing' => 2, 'completed' => 3, 'failed' => 3, 'cancelled' => 3];
        $advance = isset($order[$status]) && $order[$status] > ($order[$job['cvj_status']] ?? 0);
        $this->db->executeStatement(
            'UPDATE cattomail_validation_jobs SET cvj_status = ?, cvj_processed = GREATEST(cvj_processed, ?), cvj_counts = ?, cvj_updated_at = ?,
                cvj_last_checked_at = ?, cvj_completed_at = COALESCE(cvj_completed_at, ?) WHERE cvj_id = ?',
            [$advance ? $status : $job['cvj_status'], $processed, json_encode($counts), $this->now(), $this->now(),
                $advance && in_array($status, ['completed', 'failed', 'cancelled'], true) ? ($completedAt ?? $this->now()) : null, $jobId]
        );
    }

    public function fail(int $jobId, string $error): void
    {
        $this->db->executeStatement("UPDATE cattomail_validation_jobs SET cvj_status = 'failed', cvj_error = ?, cvj_updated_at = ? WHERE cvj_id = ?",
            [mb_substr($error, 0, 2000), $this->now(), $jobId]);
    }

    public function noteAttempt(int $jobId, string $error): void
    {
        $this->db->executeStatement('UPDATE cattomail_validation_jobs SET cvj_attempts = cvj_attempts + 1, cvj_error = ?, cvj_last_checked_at = ? WHERE cvj_id = ?',
            [mb_substr($error, 0, 2000), $this->now(), $jobId]);
    }

    public function markResultsComplete(int $jobId): void
    {
        $this->db->executeStatement('UPDATE cattomail_validation_jobs SET cvj_results_complete = TRUE, cvj_updated_at = ? WHERE cvj_id = ?', [$this->now(), $jobId]);
    }

    /**
     * Store one result, matched by external_address_reference within the job.
     * Values are kept as catto-mail sent them (a suggestion is never applied).
     *
     * @param array<string, mixed> $result a ValidationAddress
     * @return bool whether a submitted address matched
     */
    public function storeResult(int $jobId, array $result, ?string $checkedAt): bool
    {
        $reference = (string) ($result['external_address_reference'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/i', $reference) !== 1) {
            return false;
        }
        $assignments = ['cva_remote_id = :remote', 'cva_checked_at = :checked'];
        $params = ['remote' => is_string($result['id'] ?? null) ? strtolower($result['id']) : null, 'checked' => $checkedAt, 'job' => $jobId, 'ref' => strtolower($reference)];
        foreach (self::RESULT_FIELDS as $field => $column) {
            $value = $result[$field] ?? null;
            $assignments[] = $column . ' = :' . $column;
            $params[$column] = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : null);
        }
        return $this->db->executeStatement(
            'UPDATE cattomail_validation_addresses SET ' . implode(', ', $assignments) . ' WHERE cva_cvj_id = :job AND cva_s_uuid = :ref', $params
        ) > 0;
    }

    /** @return list<array<string, mixed>> */
    public function results(int $jobId, string $classification, int $offset, int $limit): array
    {
        $params = ['job' => $jobId];
        $where = '';
        if ($classification === 'pending') {
            $where = ' AND a.cva_checked_at IS NULL';
        } elseif ($classification !== '') {
            $where = ' AND a.cva_classification = :class';
            $params['class'] = $classification;
        }
        return $this->db->fetchAllAssociative(
            'SELECT a.*, s.s_id FROM cattomail_validation_addresses a LEFT JOIN subscribers s ON s.s_uuid = a.cva_s_uuid
             WHERE a.cva_cvj_id = :job' . $where . ' ORDER BY a.cva_id LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function resultCount(int $jobId, string $classification): int
    {
        $params = ['job' => $jobId];
        $where = '';
        if ($classification === 'pending') {
            $where = ' AND cva_checked_at IS NULL';
        } elseif ($classification !== '') {
            $where = ' AND cva_classification = :class';
            $params['class'] = $classification;
        }
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_validation_addresses WHERE cva_cvj_id = :job' . $where, $params);
    }

    /** @return array<string, mixed>|null the subscriber's most recent checked result */
    public function latestForSubscriber(string $subscriberUuid): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.*, j.cvj_completed_at FROM cattomail_validation_addresses a JOIN cattomail_validation_jobs j ON j.cvj_id = a.cva_cvj_id
             WHERE a.cva_s_uuid = ? AND a.cva_checked_at IS NOT NULL ORDER BY a.cva_checked_at DESC, a.cva_id DESC LIMIT 1',
            [strtolower($subscriberUuid)]
        );
        return $row === false ? null : $row;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     * @return ValidationJob
     */
    private static function hydrate(array $row): array
    {
        $counts = json_decode((string) ($row['cvj_counts'] ?? ''), true);
        return [
            'cvj_id' => (int) $row['cvj_id'], 'cvj_uuid' => (string) $row['cvj_uuid'], 'cvj_idempotency_key' => (string) $row['cvj_idempotency_key'],
            'cvj_remote_id' => $row['cvj_remote_id'] === null ? null : (string) $row['cvj_remote_id'], 'cvj_scope' => (string) $row['cvj_scope'],
            'cvj_status' => (string) $row['cvj_status'], 'cvj_total' => (int) $row['cvj_total'], 'cvj_processed' => (int) $row['cvj_processed'],
            'cvj_counts' => is_array($counts) ? array_map('intval', $counts) : [], 'cvj_results_complete' => (bool) $row['cvj_results_complete'],
            'cvj_error' => $row['cvj_error'] === null ? null : (string) $row['cvj_error'], 'cvj_created_at' => (string) $row['cvj_created_at'],
            'cvj_completed_at' => $row['cvj_completed_at'] === null ? null : (string) $row['cvj_completed_at'],
            'cvj_last_checked_at' => $row['cvj_last_checked_at'] === null ? null : (string) $row['cvj_last_checked_at'],
        ];
    }
}
