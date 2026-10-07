<?php

declare(strict_types=1);

namespace App\Maintenance;

use App\Repository\OptionRepository;
use Psr\Clock\ClockInterface;

/** When maintenance last ran, what it did, and its last failure (in `options`, for the status page and diagnostics). */
final class MaintenanceActivity
{
    private const RUN = 'maintenance:last_run_at';
    private const SUMMARY = 'maintenance:last_summary';
    private const FAILED = 'maintenance:last_failure_at';

    public function __construct(
        private readonly OptionRepository $options,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @param array<string, int> $report */
    public function ran(array $report): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->options->set(self::RUN, $now);
        $this->options->set(self::SUMMARY, implode(', ', array_map(static fn(string $task, int $n): string => $task . ': ' . $n, array_keys($report), $report)));
        if (($report['failures'] ?? 0) > 0) {
            $this->options->set(self::FAILED, $now);
        }
    }

    /** @return array{run: ?string, summary: ?string, failed: ?string} */
    public function snapshot(): array
    {
        return ['run' => $this->options->get(self::RUN), 'summary' => $this->options->get(self::SUMMARY), 'failed' => $this->options->get(self::FAILED)];
    }
}
