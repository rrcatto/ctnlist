<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\CattoMail\CattoMailConfig;
use App\Config\RuntimeSettings;
use App\Repository\CattoMailWebhookRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recurring housekeeping (ctnlist:maintenance). It removes only data that
 * can no longer be used or that the retention policy marks as temporary,
 * judged by state and expiry fields, never by age alone:
 *
 * - sign-in links that are used or expired and older than the sign-in rate
 *   windows (the rate limits count recent links);
 * - sign-in sessions that expired or were revoked (signed out);
 * - PHP sessions (CSRF tokens, flash messages) idle for longer than a
 *   sign-in (AUTH_SESSION_TTL) or a sign-in link (AUTH_MAGIC_LINK_TTL) lasts,
 *   so a signed-in user's open form, or an opened sign-in link's page, never
 *   loses its CSRF token first (PHP's own session garbage collection is off:
 *   framework.yaml, so php.ini plays no part);
 * - raw bodies of webhook events processed more than
 *   CATTOMAIL_WEBHOOK_BODY_RETENTION_DAYS ago (the event rows, outcomes and
 *   references stay; unprocessed events are never touched);
 * - rendered content of recipients of refused or cancelled send jobs older
 *   than CATTOMAIL_DETAIL_RETENTION_DAYS (never retried with that body; a
 *   requeue renders again);
 * - per-address validation results superseded by a newer result for the
 *   same subscriber, from jobs finished more than CATTOMAIL_DETAIL_RETENTION_DAYS
 *   ago (job counts and every subscriber's latest result stay);
 * - Site Log rows older than APP_SITELOG_RETENTION_DAYS, only when set (0,
 *   the default, keeps the Site Log as v5 did).
 *
 * Never touched: subscribers, consent and membership history, suppression,
 * the Send Log, smlog, send runs/jobs/recipient outcomes, idempotency keys
 * and request bodies of work that can still be retried, the queue.
 *
 * Each task works in batches of at most BATCH rows (one statement, so one
 * short transaction each) up to a per-run cap; the rest is left for the next
 * run. A dry run counts what would be done and changes nothing.
 */
final class Maintenance
{
    public const BATCH = 1000;
    public const MAX_BATCHES = 50;

    private int $batch = self::BATCH;
    private int $maxBatches = self::MAX_BATCHES;

    public function __construct(
        private readonly Connection $db,
        private readonly CattoMailWebhookRepository $webhooks,
        private readonly CattoMailConfig $config,
        private readonly RuntimeSettings $settings,
        private readonly StuckWork $stuck,
        private readonly DatabaseLock $lock,
        private readonly MaintenanceActivity $activity,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::APP_SITELOG_RETENTION_DAYS)%')] private readonly ?string $siteLogRetention = null,
    ) {
    }

    /** Smaller batches (tests); never above BATCH rows per statement. */
    public function useBatches(int $size, int $maxBatches): void
    {
        $this->batch = max(1, min(self::BATCH, $size));
        $this->maxBatches = max(1, $maxBatches);
    }

    /**
     * @return array<string, int> each task's count (done, or would be done in a dry run), the stuck work
     *     found, and `failures`; a run that finds maintenance already running returns only a `skipped` entry
     */
    public function run(bool $dryRun = false): array
    {
        if (!$this->lock->acquire(DatabaseLock::MAINTENANCE)) {
            return ['skipped (maintenance is already running)' => 1];
        }
        try {
            $report = [];
            $failures = 0;
            foreach ($this->tasks() as $name => [$apply, $count]) {
                try {
                    $report[$name] = $dryRun ? $count() : $this->batched($apply);
                } catch (\Throwable $e) {
                    // One task's failure does not stop the others; it is reported and logged.
                    $failures++;
                    $report[$name] = 0;
                    $this->logger->error('Maintenance task "{task}" failed: {error}', ['task' => $name, 'error' => $e->getMessage()]);
                }
            }
            $report['stuck work'] = $this->stuck->total();
            $report['failures'] = $failures;
            if (!$dryRun) {
                $this->activity->ran($report);
                // Counts only: never individual rows or their content.
                $this->logger->notice('Maintenance: {summary}', ['summary' => implode(', ', array_map(static fn(string $t, int $n): string => $t . ' ' . $n, array_keys($report), $report))]);
            }
            return $report;
        } finally {
            $this->lock->release(DatabaseLock::MAINTENANCE);
        }
    }

    /** @return array<string, array{0: \Closure(int): int, 1: \Closure(): int}> task => [apply one batch of at most N rows, count] */
    private function tasks(): array
    {
        $now = $this->now();
        $rateWindow = max($this->settings->int('AUTH_MAGIC_LINK_EMAIL_WINDOW', 900), $this->settings->int('AUTH_MAGIC_LINK_IP_WINDOW', 3600));
        $linkKeep = $this->ago($rateWindow);
        $sessionCutoff = time() - max($this->settings->int('AUTH_SESSION_TTL', 86400), $this->settings->int('AUTH_MAGIC_LINK_TTL', 1800));
        $bodyCutoff = $this->ago($this->config->webhookBodyRetentionDays * 86400);
        $detailCutoff = $this->ago($this->config->detailRetentionDays * 86400);
        $siteLogDays = is_numeric($this->siteLogRetention) ? max(0, (int) $this->siteLogRetention) : 0;

        $links = "FROM auth_login_tokens WHERE (alt_expires_at < :now OR alt_used_at IS NOT NULL) AND alt_created_at < :keep";
        $sessions = 'FROM auth_sessions WHERE as_expires_at < :now OR as_revoked_at IS NOT NULL';
        $content = "FROM cattomail_recipients r JOIN cattomail_send_jobs j ON j.csj_id = r.crp_csj_id
            WHERE j.csj_status IN ('failed', 'cancelled') AND COALESCE(j.csj_completed_at, j.csj_created_at) < :cutoff
              AND r.crp_status = 'not_sent' AND (r.crp_html IS NOT NULL OR r.crp_text IS NOT NULL)";
        $superseded = "FROM cattomail_validation_addresses a JOIN cattomail_validation_jobs j ON j.cvj_id = a.cva_cvj_id
            WHERE j.cvj_status IN ('completed', 'failed', 'cancelled') AND j.cvj_completed_at < :cutoff
              AND EXISTS (SELECT 1 FROM cattomail_validation_addresses n WHERE n.cva_s_uuid = a.cva_s_uuid AND n.cva_id > a.cva_id AND n.cva_checked_at IS NOT NULL)";

        $tasks = [
            'expired sign-in links removed' => [
                fn(int $n): int => (int) $this->db->executeStatement("DELETE FROM auth_login_tokens WHERE alt_id IN (SELECT alt_id {$links} ORDER BY alt_id LIMIT {$n})", ['now' => $now, 'keep' => $linkKeep]),
                fn(): int => (int) $this->db->fetchOne("SELECT COUNT(*) {$links}", ['now' => $now, 'keep' => $linkKeep]),
            ],
            'ended sign-in sessions removed' => [
                fn(int $n): int => (int) $this->db->executeStatement("DELETE FROM auth_sessions WHERE as_id IN (SELECT as_id {$sessions} ORDER BY as_id LIMIT {$n})", ['now' => $now]),
                fn(): int => (int) $this->db->fetchOne("SELECT COUNT(*) {$sessions}", ['now' => $now]),
            ],
            'expired PHP sessions removed' => [
                fn(int $n): int => (int) $this->db->executeStatement("DELETE FROM sessions WHERE ses_id IN (SELECT ses_id FROM sessions WHERE ses_stamp < :cutoff LIMIT {$n})", ['cutoff' => $sessionCutoff]),
                fn(): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM sessions WHERE ses_stamp < :cutoff', ['cutoff' => $sessionCutoff]),
            ],
            'webhook bodies pruned' => [
                fn(int $n): int => $this->webhooks->pruneBodies($bodyCutoff, $n),
                fn(): int => $this->webhooks->prunableBodies($bodyCutoff),
            ],
            'refused-job content cleared' => [
                fn(int $n): int => (int) $this->db->executeStatement(
                    "UPDATE cattomail_recipients SET crp_html = NULL, crp_text = NULL WHERE crp_id IN (SELECT r.crp_id {$content} ORDER BY r.crp_id LIMIT {$n})", ['cutoff' => $detailCutoff]),
                fn(): int => (int) $this->db->fetchOne("SELECT COUNT(*) {$content}", ['cutoff' => $detailCutoff]),
            ],
            'superseded validation results removed' => [
                // The removal and the job's "detail pruned" mark commit together.
                fn(int $n): int => $this->db->transactional(function () use ($superseded, $n, $detailCutoff, $now): int {
                    $jobs = $this->db->fetchFirstColumn(
                        "DELETE FROM cattomail_validation_addresses WHERE cva_id IN (SELECT a.cva_id {$superseded} ORDER BY a.cva_id LIMIT {$n}) RETURNING cva_cvj_id",
                        ['cutoff' => $detailCutoff]
                    );
                    if ($jobs !== []) {
                        $this->db->executeStatement('UPDATE cattomail_validation_jobs SET cvj_detail_pruned_at = COALESCE(cvj_detail_pruned_at, ?) WHERE cvj_id IN (?)',
                            [$now, array_values(array_unique(array_map('intval', $jobs)))], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
                    }
                    return count($jobs);
                }),
                fn(): int => (int) $this->db->fetchOne("SELECT COUNT(*) {$superseded}", ['cutoff' => $detailCutoff]),
            ],
        ];
        if ($siteLogDays > 0) {
            $siteCutoff = $this->ago($siteLogDays * 86400);
            $tasks['Site Log rows removed'] = [
                fn(int $n): int => (int) $this->db->executeStatement(
                    "DELETE FROM sitelog WHERE stl_id IN (SELECT stl_id FROM sitelog WHERE stl_logged_at < :cutoff ORDER BY stl_id LIMIT {$n})", ['cutoff' => $siteCutoff]),
                fn(): int => (int) $this->db->fetchOne('SELECT COUNT(*) FROM sitelog WHERE stl_logged_at < :cutoff', ['cutoff' => $siteCutoff]),
            ];
        }
        return $tasks;
    }

    /** Run one batch after another until a batch comes back short or the per-run cap is reached. */
    private function batched(\Closure $apply): int
    {
        $total = 0;
        for ($i = 0; $i < $this->maxBatches; $i++) {
            $done = $apply($this->batch);
            $total += $done;
            if ($done < $this->batch) {
                break;
            }
        }
        return $total;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function ago(int $seconds): string
    {
        return $this->clock->now()->modify('-' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }
}
