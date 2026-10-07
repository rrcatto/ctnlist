<?php

declare(strict_types=1);

namespace App\Command;

use App\Maintenance\Maintenance;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Housekeeping (App\Maintenance\Maintenance): expired sign-in data, PHP
 * sessions, raw webhook bodies and other temporary data past retention,
 * plus a count of stuck work. Separate from ctnlist:cattomail:work, which
 * moves catto-mail work on every minute; run this hourly from cron:
 *
 *   17 * * * * CTNLIST_INSTANCE_DIR=/var/www/example /path/to/shared/bin/console ctnlist:maintenance
 *
 * --dry-run counts what would be done and changes nothing. Output: one line
 * of counts (only when something was found, or with -v); never row content.
 * Exit status 1 when a task failed, 0 otherwise (including nothing to do,
 * or another maintenance run already in progress).
 */
#[AsCommand('ctnlist:maintenance', 'Remove expired sign-in data and temporary data past retention; report stuck catto-mail work')]
final class MaintenanceCommand extends Command
{
    public function __construct(
        private readonly Maintenance $maintenance,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be removed; change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $report = $this->maintenance->run($dryRun);
        $active = array_filter($report, static fn(int $n): bool => $n > 0);
        if ($active !== [] || $dryRun || $output->isVerbose()) {
            $output->writeln($this->clock->now()->format('Y-m-d H:i:s') . ' maintenance' . ($dryRun ? ' (dry run, nothing changed)' : '') . ': '
                . implode(', ', array_map(static fn(string $task, int $n): string => $task . ' ' . $n, array_keys($report), $report)));
        }
        return ($report['failures'] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
