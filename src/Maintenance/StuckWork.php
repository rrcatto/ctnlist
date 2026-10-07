<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\CattoMail\CattoMailConfig;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * What counts as stuck: work the worker (every minute) should have moved on
 * long ago. The threshold follows the installation's own reconciliation
 * interval: twice CATTOMAIL_RECONCILE_AFTER_SECONDS, at least ten minutes.
 * A job that is merely slow at catto-mail (a dispatched job waiting days for
 * final bounce knowledge) is not stuck as long as ctnlist keeps checking it.
 *
 * - send jobs not sealed: still staging or uploading after the threshold
 *   (catto-mail kept refusing temporarily, or the worker does not run);
 * - send jobs not reconciled: sealed, not final, not checked within the threshold;
 * - validation jobs not submitted, or not reconciled, likewise;
 * - opt-outs or withdrawals not reported within the threshold;
 * - webhook events not processed ten minutes after arrival.
 */
final class StuckWork
{
    public function __construct(
        private readonly Connection $db,
        private readonly CattoMailConfig $config,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Seconds after which work is stuck. */
    public function threshold(): int
    {
        return max(600, 2 * $this->config->reconcileAfterSeconds);
    }

    public function staleBefore(): string
    {
        return $this->clock->now()->modify('-' . $this->threshold() . ' seconds')->format('Y-m-d H:i:s');
    }

    /** @return array<string, int> category => count (every category always present) */
    public function counts(): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT
                (SELECT COUNT(*) FROM cattomail_send_jobs WHERE csj_status IN ('open', 'ready') AND csj_created_at < :stale) AS send_unsealed,
                (SELECT COUNT(*) FROM cattomail_send_jobs WHERE csj_status IN ('queued', 'processing', 'dispatched')
                    AND COALESCE(csj_last_checked_at, csj_submitted_at, csj_created_at) < :stale) AS send_unreconciled,
                (SELECT COUNT(*) FROM cattomail_validation_jobs WHERE cvj_status = 'pending' AND cvj_created_at < :stale) AS validation_unsubmitted,
                (SELECT COUNT(*) FROM cattomail_validation_jobs WHERE (cvj_status IN ('queued', 'processing') OR (cvj_status = 'completed' AND NOT cvj_results_complete))
                    AND COALESCE(cvj_last_checked_at, cvj_created_at) < :stale) AS validation_unreconciled,
                (SELECT COUNT(*) FROM cattomail_global_optouts WHERE cgo_status IN ('pending', 'lift_pending')
                    AND COALESCE(cgo_lift_requested_at, cgo_requested_at) < :stale) AS optouts_unreported,
                (SELECT COUNT(*) FROM cattomail_webhook_events WHERE cwe_processed_at IS NULL AND cwe_received_at < :recent) AS webhooks_unprocessed",
            ['stale' => $this->staleBefore(), 'recent' => $this->clock->now()->modify('-600 seconds')->format('Y-m-d H:i:s')]
        ) ?: [];
        return [
            'send jobs not sealed' => (int) ($row['send_unsealed'] ?? 0),
            'send jobs not reconciled' => (int) ($row['send_unreconciled'] ?? 0),
            'validation jobs not submitted' => (int) ($row['validation_unsubmitted'] ?? 0),
            'validation jobs not reconciled' => (int) ($row['validation_unreconciled'] ?? 0),
            'opt-outs not reported' => (int) ($row['optouts_unreported'] ?? 0),
            'webhook events not processed' => (int) ($row['webhooks_unprocessed'] ?? 0),
        ];
    }

    public function total(): int
    {
        return array_sum($this->counts());
    }

    /**
     * One send job's state for an administrator: Pending (staging or being
     * delivered), Stuck, Failed, Cancelled, Completed, or Awaiting
     * reconciliation (dispatched, final outcomes still arriving).
     *
     * @param array{csj_status: string, csj_created_at: string, csj_submitted_at: ?string, csj_last_checked_at: ?string} $job
     */
    public function jobCategory(array $job): string
    {
        $stale = $this->staleBefore();
        return match ($job['csj_status']) {
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            'open', 'ready' => $job['csj_created_at'] < $stale ? 'Stuck' : 'Pending',
            default => ($job['csj_last_checked_at'] ?? $job['csj_submitted_at'] ?? $job['csj_created_at']) < $stale ? 'Stuck'
                : ($job['csj_status'] === 'dispatched' ? 'Awaiting reconciliation' : 'Pending'),
        };
    }
}
