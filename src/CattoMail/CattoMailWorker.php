<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\CattoMailOptOutRepository;
use App\Repository\CattoMailSendRepository;
use App\Repository\CattoMailValidationRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The deferred side of the integration, run by `ctnlist:cattomail:work`
 * (cron, e.g. every minute). Webhooks are notifications, not the only source
 * of truth, so each run:
 *
 * 1. processes stored webhook events not yet processed;
 * 2. retries outbox send jobs not yet sealed (create / upload batches /
 *    submit, always with their stored Idempotency-Keys) once their run has
 *    finished or was abandoned;
 * 3. reports pending global opt-outs and lifts;
 * 4. submits validation jobs not yet accepted;
 * 5. reconciles by polling: send jobs and validation jobs without a final
 *    state (or with results still to fetch) whose last check is older than
 *    CATTOMAIL_RECONCILE_AFTER_SECONDS (GET /v1/send-jobs/{id} and messages,
 *    GET /v1/validation-jobs/{id} and results).
 */
final class CattoMailWorker
{
    /** A run older than this whose process never finished it is treated as abandoned. */
    private const ABANDONED_RUN_SECONDS = 3600;

    public function __construct(
        private readonly CattoMailConfig $config,
        private readonly WebhookProcessor $webhooks,
        private readonly CattoMailSendRepository $outbox,
        private readonly CattoMailSender $sender,
        private readonly SendJobSync $sendSync,
        private readonly CattoMailValidationRepository $validations,
        private readonly AddressValidation $validation,
        private readonly CattoMailOptOutRepository $optOuts,
        private readonly GlobalOptOut $globalOptOut,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, int> what was done */
    public function run(): array
    {
        $report = ['webhooks' => $this->webhooks->processPending()];
        if (!$this->config->isConfigured()) {
            return $report + ['skipped (not configured)' => 1];
        }
        $now = $this->clock->now();
        $report['send jobs flushed'] = 0;
        foreach ($this->outbox->unsealedJobs($now->modify('-' . self::ABANDONED_RUN_SECONDS . ' seconds')->format('Y-m-d H:i:s')) as $job) {
            $this->outbox->closeJob($job['csj_id']);
            $report['send jobs flushed'] += $this->attempt(fn() => $this->sender->flushJob($job['csj_id'])) ? 1 : 0;
        }
        $report['opt-outs reported'] = 0;
        foreach ($this->optOuts->unreported() as $optOut) {
            $this->globalOptOut->report($optOut);
            $report['opt-outs reported']++;
        }
        $report['validation jobs submitted'] = 0;
        foreach ($this->validations->unsubmitted() as $job) {
            $report['validation jobs submitted'] += $this->attempt(fn() => $this->validation->submit($job)) ? 1 : 0;
        }
        $before = $now->modify('-' . $this->config->reconcileAfterSeconds . ' seconds')->format('Y-m-d H:i:s');
        $report['send jobs reconciled'] = 0;
        foreach ($this->outbox->unresolvedJobs($before) as $job) {
            $report['send jobs reconciled'] += $this->attempt(fn() => $this->sendSync->reconcile($job)) ? 1 : 0;
        }
        $report['validation jobs reconciled'] = 0;
        foreach ($this->validations->unresolved($before) as $job) {
            $report['validation jobs reconciled'] += $this->attempt(fn() => $this->validation->refresh($job)) ? 1 : 0;
        }
        return $report;
    }

    private function attempt(\Closure $work): bool
    {
        try {
            $work();
            return true;
        } catch (CattoMailException $e) {
            $this->logger->warning('catto-mail worker: {error}', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
