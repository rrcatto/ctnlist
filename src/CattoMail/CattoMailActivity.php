<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\OptionRepository;
use Psr\Clock\ClockInterface;

/**
 * Operational facts for the integration status page, kept in `options`:
 * the last successful catto-mail API call, the last API error (redacted by
 * the client before it gets here) and the worker's last run. Successful calls
 * are recorded at most once a minute per process, so a large send does not
 * write a row per batch.
 */
final class CattoMailActivity
{
    public const LAST_OK = 'cattomail:last_api_ok_at';
    public const LAST_ERROR = 'cattomail:last_api_error';
    public const LAST_ERROR_AT = 'cattomail:last_api_error_at';
    public const WORKER_RUN = 'cattomail:worker_last_run_at';
    public const WORKER_SUMMARY = 'cattomail:worker_last_summary';

    private ?int $lastOkWritten = null;

    public function __construct(
        private readonly OptionRepository $options,
        private readonly ClockInterface $clock,
    ) {
    }

    public function apiSucceeded(): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($this->lastOkWritten !== null && $now - $this->lastOkWritten < 60) {
            return;
        }
        $this->lastOkWritten = $now;
        $this->options->set(self::LAST_OK, $this->now());
    }

    /** @param string $message already redacted (CattoMailClient) */
    public function apiFailed(string $message): void
    {
        $this->options->set(self::LAST_ERROR, mb_substr($message, 0, 500));
        $this->options->set(self::LAST_ERROR_AT, $this->now());
    }

    /** @param array<string, int> $report */
    public function workerRan(array $report): void
    {
        $this->options->set(self::WORKER_RUN, $this->now());
        $this->options->set(self::WORKER_SUMMARY, implode(', ', array_map(static fn(string $task, int $n): string => $task . ': ' . $n, array_keys($report), $report)));
    }

    /** @return array{last_ok: ?string, last_error: ?string, last_error_at: ?string, worker_run: ?string, worker_summary: ?string} */
    public function snapshot(): array
    {
        return [
            'last_ok' => $this->options->get(self::LAST_OK),
            'last_error' => $this->options->get(self::LAST_ERROR),
            'last_error_at' => $this->options->get(self::LAST_ERROR_AT),
            'worker_run' => $this->options->get(self::WORKER_RUN),
            'worker_summary' => $this->options->get(self::WORKER_SUMMARY),
        ];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
