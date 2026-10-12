<?php

declare(strict_types=1);

namespace App\Command;

use App\Campaign\SampleTemplates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds the three sample email templates (App\Campaign\SampleTemplates:
 * a newsletter, an announcement and a letter) to this installation, for an
 * administrator to use and adapt under Admin > Templates:
 *
 *   CTNLIST_INSTANCE_DIR=/var/www/example /path/to/shared/bin/console ctnlist:templates:samples
 *
 * A sample whose name is already taken is left alone, so the command can be
 * run again and never replaces a template that was edited. Development
 * (`bin/dev up`) runs it; on a new installation it is an optional step.
 */
#[AsCommand('ctnlist:templates:samples', 'Add the sample email templates that are not there yet')]
final class SampleTemplatesCommand extends Command
{
    public function __construct(private readonly SampleTemplates $samples)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->samples->install();
        if ($result['added'] !== []) {
            $output->writeln('Added: ' . implode(', ', $result['added']) . '.');
        }
        if ($result['kept'] !== []) {
            $output->writeln('Already there, left as they are: ' . implode(', ', $result['kept']) . '.');
        }
        return Command::SUCCESS;
    }
}
