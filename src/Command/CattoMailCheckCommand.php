<?php

declare(strict_types=1);

namespace App\Command;

use App\Campaign\MessageService;
use App\CattoMail\AddressValidation;
use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailException;
use App\CattoMail\CattoMailHealth;
use App\CattoMail\SendJobSync;
use App\Config\SiteConfig;
use App\Repository\CattoMailSendRepository;
use App\Repository\CattoMailValidationRepository;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The catto-mail connection from the command line, and the optional real
 * end-to-end check of the development route (bin/dev test-cattomail).
 *
 * Without options: the configuration (never a secret) and the connection
 * probe (CattoMailHealth: one read, nothing created). Exit 0 only when
 * catto-mail is reachable and accepts the key.
 *
 * With --e2e, and only in APP_ENV dev or test against a local development
 * catto-mail (CATTOMAIL_API_CONNECT_HOST set, or a localhost/.test/.localhost
 * API host), so production can never be targeted by accident:
 *   1. a proof of a message (--muid, default the newest) to --to (default
 *      MAIL_TEST_ADDRESS): one transactional send job;
 *   2. a validation job for the subscriber with that address, if there is one;
 *   3. both followed until catto-mail has finished them (--wait seconds),
 *      by polling the documented GET endpoints, as the worker would.
 * catto-mail's development mail capture receives the proof; nothing leaves
 * the machine. Webhooks, if the route delivers them, update the same rows.
 */
#[AsCommand('ctnlist:cattomail:check', 'Check the catto-mail connection; with --e2e, run a small real proof and validation against the development catto-mail')]
final class CattoMailCheckCommand extends Command
{
    private const FINAL_JOB = ['dispatched', 'completed', 'failed', 'cancelled'];

    public function __construct(
        private readonly CattoMailConfig $config,
        private readonly CattoMailHealth $health,
        private readonly SiteConfig $site,
        private readonly MessageRepository $messages,
        private readonly MessageService $messageService,
        private readonly CattoMailSendRepository $outbox,
        private readonly SendJobSync $sendSync,
        private readonly SubscriberRepository $subscribers,
        private readonly AddressValidation $validation,
        private readonly CattoMailValidationRepository $validations,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('e2e', null, InputOption::VALUE_NONE, 'Also send one proof and one validation job through the development catto-mail')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Proof recipient (default MAIL_TEST_ADDRESS)')
            ->addOption('muid', null, InputOption::VALUE_REQUIRED, 'Message to proof (default the newest message)')
            ->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Seconds to follow the jobs', '120')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between checks', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('API:          ' . ($this->config->isConfigured() ? $this->config->apiRoot() : '(not configured)'));
        if ($this->config->connectHost !== '') {
            $output->writeln('Connects via: ' . $this->config->connectHost . ' (development)');
        }
        $output->writeln('Webhook:      ' . $this->site->baseUrl . 'cattomail/webhook, secret ' . ($this->config->hasWebhookSecret() ? 'set' : 'NOT set')
            . ($this->config->hasPreviousWebhookSecret() ? ', previous secret set' : ''));
        $health = $this->health->check();
        [, $label, $advice] = CattoMailHealth::STATES[$health['state']];
        $output->writeln('Connection:   ' . $label . ($health['state'] === CattoMailHealth::OK ? '' : ' - ' . $advice));
        $output->writeln('              ' . $health['detail'], OutputInterface::VERBOSITY_VERBOSE);
        if ($health['state'] !== CattoMailHealth::OK) {
            return Command::FAILURE;
        }
        if (!$input->getOption('e2e')) {
            return Command::SUCCESS;
        }

        $refusal = $this->e2eRefusal();
        if ($refusal !== null) {
            $output->writeln('<error>Not running the end-to-end check: ' . $refusal . '</error>');
            return Command::FAILURE;
        }
        $to = trim((string) ($input->getOption('to') ?? $this->site->testEmail));
        $muid = (string) ($input->getOption('muid') ?? ($this->messages->choices()[0]['m_uniqid'] ?? ''));
        if ($muid === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>Need a message (--muid) and a valid recipient (--to or MAIL_TEST_ADDRESS).</error>');
            return Command::FAILURE;
        }

        $output->writeln("Proof of {$muid} to {$to} ...");
        try {
            $outcome = $this->messageService->sendProof($muid, $to);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln('  ' . $outcome->status . ($outcome->problem !== null ? ': ' . $outcome->problem : ''));
        $job = $this->outbox->jobsForMessage($muid)[0] ?? null;
        if ($job === null || $outcome->problem !== null) {
            return Command::FAILURE;
        }
        $output->writeln('  send job ' . $job['csj_id'] . ' (catto-mail ' . ($job['csj_remote_id'] ?? '-') . ')');

        $validationId = null;
        $subscriber = $this->subscribers->findIdentityByEmail($to);
        if ($subscriber !== null) {
            $ids = $this->validation->create('End-to-end check', [['s_uuid' => $subscriber['s_uuid'], 's_email' => $subscriber['s_email']]], null);
            $validationId = $ids[0] ?? null;
            $output->writeln('Validation job ' . $validationId . ' for ' . $to);
        } else {
            $output->writeln('No subscriber with that address: validation skipped (add one, or pass --to of a subscriber).');
        }

        $deadline = time() + max(0, (int) $input->getOption('wait'));
        do {
            sleep(max(0, (int) $input->getOption('interval')));
            try {
                $current = $this->outbox->job($job['csj_id']);
                if ($current !== null && !in_array($current['csj_status'], self::FINAL_JOB, true)) {
                    $this->sendSync->reconcile($current);
                }
                $vjob = $validationId !== null ? $this->validations->job($validationId) : null;
                if ($vjob !== null && !$vjob['cvj_results_complete']) {
                    $this->validation->refresh($vjob);
                }
            } catch (CattoMailException $e) {
                $output->writeln('  ' . $e->getMessage());
            }
            $current = $this->outbox->job($job['csj_id']);
            $vjob = $validationId !== null ? $this->validations->job($validationId) : null;
            $recipients = $this->outbox->recipientsForJob($job['csj_id'], 0, 5);
            $output->writeln(sprintf('  job %s, recipient %s%s', $current['csj_status'] ?? '?', $recipients[0]['crp_status'] ?? '?',
                $vjob !== null ? ', validation ' . $vjob['cvj_status'] . ($vjob['cvj_results_complete'] ? ' (results stored)' : '') : ''));
            $sent = $current !== null && in_array($current['csj_status'], self::FINAL_JOB, true);
            $validated = $vjob === null || $vjob['cvj_results_complete'];
        } while (!($sent && $validated) && time() < $deadline);

        if (!($sent && $validated)) {
            $output->writeln('<error>Not finished in time; the worker keeps following both (Admin > Delivery).</error>');
            return Command::FAILURE;
        }
        $output->writeln($current['csj_status'] === 'failed' ? '<error>catto-mail failed the proof job.</error>' : 'Done: check the proof in catto-mail\'s development mail capture.');
        return $current['csj_status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }

    /** Why --e2e must not run here, or null. */
    private function e2eRefusal(): ?string
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            return 'APP_ENV is ' . $this->environment . ' (only dev or test).';
        }
        $host = strtolower((string) parse_url($this->config->apiRoot(), PHP_URL_HOST));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
        if ($this->config->connectHost === '' && !$local) {
            return 'the API host ' . $host . ' is not a local development catto-mail.';
        }
        return null;
    }
}
