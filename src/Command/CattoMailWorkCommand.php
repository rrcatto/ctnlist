<?php

declare(strict_types=1);

namespace App\Command;

use App\CattoMail\CattoMailWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the deferred catto-mail work once (or repeatedly with --loop):
 * webhook events, outbox retries, opt-outs, validation submissions and the
 * polling reconciliation. Run it from cron, e.g. every minute:
 *
 *   CTNLIST_INSTANCE_DIR=/var/www/example bin/console ctnlist:cattomail:work
 */
#[AsCommand('ctnlist:cattomail:work', 'Process catto-mail webhooks, retry the send outbox and reconcile jobs by polling')]
final class CattoMailWorkCommand extends Command
{
    public function __construct(private readonly CattoMailWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('loop', null, InputOption::VALUE_REQUIRED, 'Repeat every N seconds until stopped', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = $input->getOption('loop');
        do {
            foreach ($this->worker->run() as $task => $count) {
                if ($count > 0 || $output->isVerbose()) {
                    $output->writeln(sprintf('%s: %d', $task, $count));
                }
            }
            if ($interval !== null) {
                sleep(max(5, (int) $interval));
            }
        } while ($interval !== null);
        return Command::SUCCESS;
    }
}
