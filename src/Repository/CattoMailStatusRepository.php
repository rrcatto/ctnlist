<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Read-only figures for the catto-mail integration status page: work that
 * is pending, and the send runs (paginated). One logical ctnlist run (a queue
 * run, proof, resend or forward) can span several catto-mail send jobs (at
 * most 10,000 recipients each); the run is what an administrator started.
 *
 * @phpstan-type Run array{cr_id: int, cr_uuid: string, cr_kind: string, cr_muid: string, cr_created_at: string, cr_finished_at: ?string,
 *     m_subject: ?string, jobs: int, recipients: int, statuses: string, failed: int, unsealed: int, stuck: int}
 */
final class CattoMailStatusRepository
{
    private const RUN_COLUMNS = "r.cr_id, r.cr_uuid, r.cr_kind, r.cr_muid, r.cr_created_at, r.cr_finished_at, m.m_subject,
        COUNT(j.csj_id) AS jobs, COALESCE(SUM(j.csj_total), 0) AS recipients,
        COALESCE(STRING_AGG(DISTINCT j.csj_status, ', '), '') AS statuses,
        COUNT(j.csj_id) FILTER (WHERE j.csj_status = 'failed') AS failed,
        COUNT(j.csj_id) FILTER (WHERE j.csj_status IN ('open', 'ready')) AS unsealed,
        COUNT(j.csj_id) FILTER (WHERE (j.csj_status IN ('open', 'ready') AND j.csj_created_at < :stale)
            OR (j.csj_status IN ('queued', 'processing', 'dispatched') AND COALESCE(j.csj_last_checked_at, j.csj_submitted_at, j.csj_created_at) < :stale)) AS stuck";

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT
                (SELECT COUNT(*) FROM cattomail_send_jobs WHERE csj_status IN ('open', 'ready')) AS jobs_unsealed,
                (SELECT COUNT(*) FROM cattomail_send_jobs WHERE csj_status IN ('queued', 'processing', 'dispatched')) AS jobs_in_flight,
                (SELECT COUNT(*) FROM cattomail_send_jobs WHERE csj_status = 'failed') AS jobs_failed,
                (SELECT COUNT(*) FROM cattomail_send_jobs WHERE csj_status = 'cancelled' AND csj_total = 0 AND (csj_remote_id IS NOT NULL OR csj_attempts > 0)) AS jobs_empty_remote,
                (SELECT COUNT(*) FROM cattomail_recipients WHERE crp_status = 'staged') AS recipients_staged,
                (SELECT COUNT(*) FROM cattomail_validation_jobs WHERE cvj_status IN ('pending', 'queued', 'processing')
                    OR (cvj_status = 'completed' AND NOT cvj_results_complete)) AS validations_pending,
                (SELECT COUNT(*) FROM cattomail_global_optouts WHERE cgo_status IN ('pending', 'lift_pending')) AS optouts_pending,
                (SELECT COUNT(*) FROM cattomail_global_optouts WHERE cgo_status = 'rejected') AS optouts_rejected,
                (SELECT COUNT(*) FROM cattomail_webhook_events WHERE cwe_processed_at IS NULL) AS webhooks_unprocessed,
                (SELECT COUNT(*) FROM cattomail_webhook_events WHERE cwe_outcome LIKE 'failed:%') AS webhooks_failed"
        ) ?: [];
        return array_map('intval', $row);
    }

    /**
     * One page of send runs that have jobs, newest first.
     *
     * @param string $staleBefore StuckWork::staleBefore()
     * @return list<Run>
     */
    public function runs(string $staleBefore, int $offset, int $limit): array
    {
        return array_map(self::hydrateRun(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::RUN_COLUMNS . '
             FROM (SELECT * FROM cattomail_runs r0 WHERE EXISTS (SELECT 1 FROM cattomail_send_jobs x WHERE x.csj_cr_id = r0.cr_id)
                   ORDER BY r0.cr_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset) . ') r
             JOIN cattomail_send_jobs j ON j.csj_cr_id = r.cr_id
             LEFT JOIN messages m ON m.m_uniqid = r.cr_muid
             GROUP BY r.cr_id, r.cr_uuid, r.cr_kind, r.cr_muid, r.cr_created_at, r.cr_finished_at, m.m_subject
             ORDER BY r.cr_id DESC',
            ['stale' => $staleBefore]
        ));
    }

    public function runCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_runs r WHERE EXISTS (SELECT 1 FROM cattomail_send_jobs x WHERE x.csj_cr_id = r.cr_id)');
    }

    /** @return Run|null */
    public function run(string $uuid, string $staleBefore = '1970-01-01 00:00:00'): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1) {
            return null;
        }
        $row = $this->db->fetchAssociative(
            'SELECT ' . self::RUN_COLUMNS . '
             FROM cattomail_runs r
             LEFT JOIN cattomail_send_jobs j ON j.csj_cr_id = r.cr_id
             LEFT JOIN messages m ON m.m_uniqid = r.cr_muid
             WHERE r.cr_uuid = :uuid GROUP BY r.cr_id, m.m_subject',
            ['uuid' => strtolower($uuid), 'stale' => $staleBefore]
        );
        return $row === false ? null : self::hydrateRun($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return Run
     */
    private static function hydrateRun(array $row): array
    {
        return [
            'cr_id' => (int) $row['cr_id'], 'cr_uuid' => (string) $row['cr_uuid'], 'cr_kind' => (string) $row['cr_kind'],
            'cr_muid' => (string) $row['cr_muid'], 'cr_created_at' => (string) $row['cr_created_at'],
            'cr_finished_at' => $row['cr_finished_at'] === null ? null : (string) $row['cr_finished_at'],
            'm_subject' => $row['m_subject'] === null ? null : (string) $row['m_subject'],
            'jobs' => (int) $row['jobs'], 'recipients' => (int) $row['recipients'], 'statuses' => (string) $row['statuses'],
            'failed' => (int) $row['failed'], 'unsealed' => (int) $row['unsealed'], 'stuck' => (int) $row['stuck'],
        ];
    }
}
