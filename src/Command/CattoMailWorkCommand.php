<?php

declare(strict_types=1);

namespace App\Command;

use App\CattoMail\CattoMailWorker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the deferred catto-mail work once (or repeatedly with --loop):
 * webhook events, outbox retries, opt-outs, validation submissions and the
 * polling reconciliation. Webhooks are the primary path; this is the safety
 * net, so run it from cron, e.g. every minute:
 *
 *   * * * * * CTNLIST_INSTANCE_DIR=/var/www/example /path/to/shared/bin/console ctnlist:cattomail:work
 *
 * Output (one line per pass that did or failed something; every pass with
 * -v) is meant for cron logs: counts only, never keys, secrets or message
 * content. Exit status 1 when an item failed (it stays for the next pass),
 * 0 otherwise, including when there was nothing to do or another worker was
 * already running.
 */
#[AsCommand('ctnlist:cattomail:work', 'Process catto-mail webhooks, retry the send outbox and reconcile jobs by polling')]
final class CattoMailWorkCommand extends Command
{
    public function __construct(
        private readonly CattoMailWorker $worker,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('loop', null, InputOption::VALUE_REQUIRED, 'Repeat every N seconds (at least 5) until stopped', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = $input->getOption('loop');
        $failed = false;
        do {
            $report = $this->worker->run();
            $failures = $report['failures'] ?? 0;
            $failed = $failed || $failures > 0;
            $active = array_filter($report, static fn(int $count): bool => $count > 0);
            if ($active !== [] || $output->isVerbose()) {
                $output->writeln($this->clock->now()->format('Y-m-d H:i:s') . ' catto-mail worker: '
                    . implode(', ', array_map(static fn(string $task, int $n): string => $task . ' ' . $n, array_keys($report), $report)));
            }
            if ($interval !== null) {
                sleep(max(5, (int) $interval));
            }
        } while ($interval !== null);
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
