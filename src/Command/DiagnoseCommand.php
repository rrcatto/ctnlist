<?php

declare(strict_types=1);

namespace App\Command;

use App\CattoMail\CattoMailActivity;
use App\CattoMail\CattoMailConfig;
use App\Config\SettingsCipher;
use App\Config\SiteConfig;
use App\Maintenance\MaintenanceActivity;
use App\Maintenance\StuckWork;
use App\Repository\SettingRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An installation's configuration and health from the command line (for
 * server administration without a browser). Never prints a secret value:
 * secrets are reported as set or not set.
 *
 * ERROR lines make the exit status 1 (the installation cannot work as
 * configured); WARN lines do not.
 */
#[AsCommand('ctnlist:diagnose', 'Check the installation: required settings, secrets present, obsolete settings, writable directories, database, worker and maintenance')]
final class DiagnoseCommand extends Command
{
    /** Settings ctnlist no longer reads (kept in step with bin/dev OBSOLETE_ENV). */
    public const OBSOLETE = ['MAIL_UNSUBSCRIBE_ADDRESS', 'MAIL_SMTP_SERVERS_JSON', 'MAIL_BATCH_SIZE', 'MAIL_BATCH_DELAY', 'MAIL_BOUNCE_LIMIT', 'APP_STORE_URL'];

    private int $errors = 0;

    public function __construct(
        private readonly SiteConfig $site,
        private readonly CattoMailConfig $cattoMail,
        private readonly SettingsCipher $cipher,
        private readonly SettingRepository $settings,
        private readonly Connection $db,
        private readonly CattoMailActivity $worker,
        private readonly MaintenanceActivity $maintenance,
        private readonly StuckWork $stuck,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.secret%')] #[\SensitiveParameter] private readonly string $appSecret,
        #[Autowire('%kernel.cache_dir%')] private readonly string $cacheDir,
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
        #[Autowire('%app.log_dir%')] private readonly string $appLogDir,
        #[Autowire('%env(default::MAILER_DSN)%')] #[\SensitiveParameter] private readonly ?string $mailerDsn = null,
        #[Autowire('%env(default::SUPPRESSION_PROVIDER)%')] private readonly ?string $suppression = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->errors = 0;
        $say = function (string $level, string $message) use ($output): void {
            if ($level === 'ERROR') {
                $this->errors++;
            }
            $output->writeln(str_pad($level, 6) . $message);
        };

        // Required settings.
        trim($this->appSecret) === '' ? $say('ERROR', 'APP_SECRET is not set (generate: openssl rand -hex 32).') : $say('OK', 'APP_SECRET set');
        trim($this->site->instanceId) === '' ? $say('ERROR', 'APP_INSTANCE_ID is not set (generate: openssl rand -hex 16).') : $say('OK', 'APP_INSTANCE_ID set');
        $say(str_starts_with($this->site->baseUrl, 'https://') ? 'OK' : 'WARN', 'APP_BASE_URL ' . $this->site->baseUrl
            . (str_starts_with($this->site->baseUrl, 'https://') ? '' : ' (not https: catto-mail cannot send subscription mail, it needs https unsubscribe links)'));

        // Database.
        try {
            $this->db->fetchOne('SELECT 1');
            $say('OK', 'database reachable');
        } catch (\Throwable $e) {
            $say('ERROR', 'database not reachable: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Secrets: present or not, never their values.
        $stored = array_filter($this->settings->all(), static fn(array $s): bool => $s['secret']);
        $keyProblem = $this->cipher->problem();
        if ($stored !== [] && $keyProblem !== null) {
            $say('ERROR', 'secret settings are stored in the database but ' . $keyProblem . ' (restore the APP_SETTINGS_KEY they were saved with)');
        } else {
            $say($keyProblem === null ? 'OK' : 'WARN', 'APP_SETTINGS_KEY ' . ($keyProblem === null ? 'set' : 'not usable: ' . $keyProblem) . ($stored === [] ? ' (no encrypted settings stored)' : ''));
        }
        $mailer = trim((string) $this->mailerDsn) !== '' || isset($this->settings->all()['MAILER_DSN']);
        $say($mailer ? 'OK' : 'WARN', 'MAILER_DSN ' . ($mailer ? 'set' : 'not set (transactional mail such as sign-in links cannot be sent)'));
        $problem = $this->cattoMail->problem();
        $say($problem === null ? 'OK' : 'WARN', $problem === null ? 'catto-mail configured: ' . $this->cattoMail->apiRoot() : $problem);
        $say($this->cattoMail->hasWebhookSecret() ? 'OK' : 'WARN', 'CATTOMAIL_WEBHOOK_SECRET ' . ($this->cattoMail->hasWebhookSecret() ? 'set' : 'not set: webhooks are refused'));
        if ($this->cattoMail->hasPreviousWebhookSecret()) {
            $say('WARN', 'CATTOMAIL_WEBHOOK_SECRET_PREVIOUS set: remove it once catto-mail\'s rotation overlap has passed');
        }
        $say('OK', 'suppression provider: ' . ($this->suppression ?: 'banlist'));

        // Obsolete settings still present in the environment.
        foreach (self::OBSOLETE as $name) {
            if (array_key_exists($name, $_SERVER) || array_key_exists($name, $_ENV)) {
                $say('WARN', $name . ' is set but no longer used; remove it from .env');
            }
        }

        // Writable directories.
        foreach (['Symfony cache (var/cache)' => $this->cacheDir, 'Symfony logs (var/log)' => $this->logsDir, 'application logs (APP_LOG_DIR)' => $this->appLogDir,
            'contact log directory' => dirname($this->site->contactLogFile)] as $label => $dir) {
            // A directory that does not exist yet is fine when it can be created (Symfony creates var/log on first use).
            $writable = is_dir($dir) ? is_writable($dir) : is_dir(dirname($dir)) && is_writable(dirname($dir));
            $writable ? $say('OK', $label . ' writable: ' . $dir) : $say('ERROR', $label . ' not writable or missing: ' . $dir);
        }

        // Worker, maintenance and stuck work.
        $worker = $this->worker->snapshot();
        $workerAge = $worker['worker_run'] === null ? null : $this->clock->now()->getTimestamp() - (int) strtotime($worker['worker_run']);
        $say($problem !== null || ($workerAge !== null && $workerAge < 900) ? 'OK' : 'WARN', 'catto-mail worker (ctnlist:cattomail:work, every minute): '
            . ($worker['worker_run'] === null ? 'never ran' : 'last ran ' . $worker['worker_run']) . ($worker['worker_failed'] !== null ? ', last failure ' . $worker['worker_failed'] : ''));
        $maintenance = $this->maintenance->snapshot();
        $maintenanceAge = $maintenance['run'] === null ? null : $this->clock->now()->getTimestamp() - (int) strtotime($maintenance['run']);
        $say($maintenanceAge !== null && $maintenanceAge < 7200 ? 'OK' : 'WARN', 'maintenance (ctnlist:maintenance, hourly): '
            . ($maintenance['run'] === null ? 'never ran' : 'last ran ' . $maintenance['run']) . ($maintenance['failed'] !== null ? ', last failure ' . $maintenance['failed'] : ''));
        $say('OK', sprintf('reconciliation after %d s; webhook bodies kept %d days; refused content and superseded validation results kept %d days',
            $this->cattoMail->reconcileAfterSeconds, $this->cattoMail->webhookBodyRetentionDays, $this->cattoMail->detailRetentionDays));
        $stuck = $this->stuck->total();
        $say($stuck === 0 ? 'OK' : 'WARN', $stuck === 0 ? 'no stuck catto-mail work' : $stuck . ' stuck catto-mail item(s): see Admin > Sending > Delivery');

        return $this->errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
