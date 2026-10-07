<?php

declare(strict_types=1);

namespace App\Command;

use App\CattoMail\CattoMailActivity;
use App\CattoMail\CattoMailConfig;
use App\Config\ConfigFingerprint;
use App\Config\ProductionReadiness;
use App\Config\RuntimeSettings;
use App\Config\SettingsCipher;
use App\Config\SiteConfig;
use App\Maintenance\MaintenanceActivity;
use App\Maintenance\StuckWork;
use App\Repository\SettingRepository;
use App\Suppression\SuppressionChecker;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An installation's configuration and health from the command line (for
 * server administration without a browser), and the preflight before an
 * installation goes live. Never prints a secret value (secrets are reported
 * as set or not set) and never sends mail or calls catto-mail.
 *
 * Production rules (ProductionReadiness, compiled assets, file permissions)
 * apply when APP_ENV=prod, or with --production to check an installation
 * before switching it to production.
 *
 * ERROR lines make the exit status 1 (the installation cannot work as
 * configured); WARN lines do not.
 */
#[AsCommand('ctnlist:diagnose', 'Check the installation (and with --production, or under APP_ENV=prod, its readiness for production): settings, secrets present, PHP extensions, schema, assets, permissions, worker and maintenance')]
final class DiagnoseCommand extends Command
{
    /** Settings ctnlist no longer reads (kept in step with bin/dev OBSOLETE_ENV). */
    public const OBSOLETE = ['MAIL_UNSUBSCRIBE_ADDRESS', 'MAIL_SMTP_SERVERS_JSON', 'MAIL_BATCH_SIZE', 'MAIL_BATCH_DELAY', 'MAIL_BOUNCE_LIMIT', 'APP_STORE_URL'];

    /** PHP extensions ctnlist needs, and what for. */
    public const REQUIRED_EXTENSIONS = [
        'pdo_pgsql' => 'PostgreSQL', 'openssl' => 'encrypted settings', 'gd' => 'profile pictures', 'mbstring' => 'text handling',
        'fileinfo' => 'upload type checks', 'dom' => 'the archive HTML sanitiser', 'xml' => 'Symfony configuration', 'session' => 'CSRF tokens and messages',
    ];
    /** Extensions that are not required but should be present. */
    public const RECOMMENDED_EXTENSIONS = ['intl' => 'international addresses and text', 'curl' => 'faster HTTPS to catto-mail (HTTP/2, connection reuse)'];

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
        private readonly RuntimeSettings $runtime,
        private readonly SuppressionChecker $suppressionChecker,
        private readonly ConfigFingerprint $fingerprint,
        private readonly AssetMapperInterface $assets,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.instance_dir%')] private readonly string $instanceDir,
        #[Autowire('%kernel.secret%')] #[\SensitiveParameter] private readonly string $appSecret,
        #[Autowire('%kernel.cache_dir%')] private readonly string $cacheDir,
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
        #[Autowire('%app.log_dir%')] private readonly string $appLogDir,
        #[Autowire('%env(default::MAILER_DSN)%')] #[\SensitiveParameter] private readonly ?string $mailerDsn = null,
        #[Autowire('%env(default::SUPPRESSION_PROVIDER)%')] private readonly ?string $suppression = null,
        #[Autowire('%env(default::TRUSTED_PROXIES)%')] private readonly ?string $trustedProxies = null,
        #[Autowire('%env(default::CATTOMAIL_API_CONNECT_HOST)%')] private readonly ?string $connectHost = null,
        #[Autowire('%env(default::CATTOMAIL_WEBHOOK_SECRET)%')] #[\SensitiveParameter] private readonly ?string $webhookSecret = null,
        #[Autowire('%env(default::CATTOMAIL_WEBHOOK_SECRET_PREVIOUS)%')] #[\SensitiveParameter] private readonly ?string $previousWebhookSecret = null,
        #[Autowire('%env(default::APP_ADMIN_EMAIL)%')] private readonly ?string $adminEmail = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('production', null, InputOption::VALUE_NONE, 'Apply the production rules even when APP_ENV is not prod (preflight before go-live)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->errors = 0;
        $production = $input->getOption('production') === true || $this->environment === 'prod';
        $say = function (string $level, string $message) use ($output): void {
            if ($level === 'ERROR') {
                $this->errors++;
            }
            $output->writeln(str_pad($level, 6) . $message);
        };
        $output->writeln('ctnlist ' . SiteConfig::VERSION . ', installation ' . $this->instanceDir . ', APP_ENV=' . $this->environment
            . ($production ? ' (production rules)' : ''));

        // Required settings.
        trim($this->appSecret) === '' ? $say('ERROR', 'APP_SECRET is not set (generate: openssl rand -hex 32).') : $say('OK', 'APP_SECRET set');
        trim($this->site->instanceId) === '' ? $say('ERROR', 'APP_INSTANCE_ID is not set (generate: openssl rand -hex 16).') : $say('OK', 'APP_INSTANCE_ID set');
        if (!$production) {
            $say(str_starts_with($this->site->baseUrl, 'https://') ? 'OK' : 'WARN', 'APP_BASE_URL ' . $this->site->baseUrl
                . (str_starts_with($this->site->baseUrl, 'https://') ? '' : ' (not https: catto-mail cannot send subscription mail, it needs https unsubscribe links)'));
        }

        // PHP extensions (of this PHP binary: run diagnose with the PHP version PHP-FPM uses).
        foreach (self::REQUIRED_EXTENSIONS as $extension => $purpose) {
            extension_loaded($extension) ? $say('OK', 'PHP extension ' . $extension) : $say('ERROR', 'PHP extension ' . $extension . ' is missing (' . $purpose . ')');
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $extension => $purpose) {
            if (!extension_loaded($extension)) {
                $say('WARN', 'PHP extension ' . $extension . ' is not loaded (recommended: ' . $purpose . ')');
            }
        }

        // Database.
        try {
            $this->db->fetchOne('SELECT 1');
            $say('OK', 'database reachable');
        } catch (\Throwable $e) {
            $say('ERROR', 'database not reachable: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // The suppression database (banlist): checked at confirmation, queue build and before every send.
        if (($this->suppression ?: 'banlist') === 'banlist') {
            try {
                $this->suppressionChecker->isSuppressed('ctnlist-diagnose-probe@gmail.com'); // a read-only lookup
                $say('OK', 'suppression database (banlist) reachable');
            } catch (\Throwable $e) {
                $say('ERROR', 'suppression database (banlist) not reachable: ' . $e->getMessage() . ' (GDB_ENV_DIRECTORY/GDB_ENV_FILE and ban.env)');
            }
        }

        // Schema: every migration of this code version has run.
        $migrations = array_map(static fn(string $file): string => (string) strstr(basename($file), '_', true), glob($this->projectDir . '/database/migrations/domain/*.php') ?: []);
        try {
            $applied = array_map('strval', $this->db->fetchFirstColumn('SELECT version FROM phinxlog'));
            $missing = array_diff($migrations, $applied);
            $missing === [] ? $say('OK', 'database schema up to date (' . count($migrations) . ' migration' . (count($migrations) === 1 ? '' : 's') . ')')
                : $say('ERROR', 'database migrations not run: ' . implode(', ', $missing) . ' (run: composer migrate-paralegal)');
        } catch (\Throwable) {
            $say('ERROR', 'database schema missing (no phinxlog table; run: composer migrate-paralegal)');
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
        // Campaign content is delivered only by catto-mail: in production it must be configured.
        $missingLevel = $production ? 'ERROR' : 'WARN';
        $say($problem === null ? 'OK' : $missingLevel, $problem === null ? 'catto-mail configured: ' . $this->cattoMail->apiRoot() : $problem);
        $say($this->cattoMail->hasWebhookSecret() ? 'OK' : $missingLevel, 'CATTOMAIL_WEBHOOK_SECRET ' . ($this->cattoMail->hasWebhookSecret() ? 'set' : 'not set: webhooks are refused'));
        if ($this->cattoMail->hasPreviousWebhookSecret()) {
            $say('WARN', 'CATTOMAIL_WEBHOOK_SECRET_PREVIOUS set: remove it once catto-mail\'s rotation overlap has passed');
        }
        if (!$production) {
            $say('OK', 'suppression provider: ' . ($this->suppression ?: 'banlist'));
        }

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

        if ($production) {
            $this->production($say);
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
        if ($worker['worker_config'] !== null) {
            $worker['worker_config'] === $this->fingerprint->value() ? $say('OK', 'the worker runs with this configuration')
                : $say('WARN', 'the worker last ran with a different configuration (installation, environment, database, base URL, catto-mail credentials or keys): '
                    . 'its cron entry must use CTNLIST_INSTANCE_DIR=' . $this->instanceDir . ' and nothing else');
        }
        $stuck = $this->stuck->total();
        $say($stuck === 0 ? 'OK' : 'WARN', $stuck === 0 ? 'no stuck catto-mail work' : $stuck . ' stuck catto-mail item(s): see Admin > Sending > Delivery');

        return $this->errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param \Closure(string, string): void $say */
    private function production(\Closure $say): void
    {
        try {
            $mailerDsn = $this->runtime->get('MAILER_DSN');
        } catch (\Throwable) {
            $mailerDsn = (string) $this->mailerDsn; // the stored override cannot be decrypted: reported above
        }
        $addresses = ['APP_ADMIN_EMAIL' => (string) $this->adminEmail];
        foreach (['MAIL_FROM_ADDRESS', 'MAIL_ADMIN_ADDRESS', 'MAIL_TEST_ADDRESS', 'CONTACT_MAIL_ADDRESS', 'MAIL_BOUNCE_ADDRESS'] as $name) {
            $addresses[$name] = $this->runtime->get($name);
        }
        foreach (ProductionReadiness::check([
            'environment' => $this->environment,
            'debug' => $this->debug,
            'app_secret' => $this->appSecret,
            'base_url' => $this->site->baseUrl,
            'mailer_dsn' => $mailerDsn,
            'suppression' => (string) $this->suppression,
            'connect_host' => (string) $this->connectHost,
            'trusted_proxies' => (string) $this->trustedProxies,
            'cattomail_secrets' => ['CATTOMAIL_API_KEY' => $this->cattoMail->apiKey(), 'CATTOMAIL_WEBHOOK_SECRET' => (string) $this->webhookSecret,
                'CATTOMAIL_WEBHOOK_SECRET_PREVIOUS' => (string) $this->previousWebhookSecret],
            'addresses' => $addresses,
        ]) as [$level, $message]) {
            $say($level, $message);
        }

        // The installation's web root: its front controller must load this code tree, and its static copies must be current.
        $public = rtrim($this->instanceDir, '/') . '/public_html';
        $index = is_file($public . '/index.php') ? (string) file_get_contents($public . '/index.php') : '';
        $shared = preg_match('/\$sharedDirectory\s*=\s*\'([^\']+)\'/', $index, $m) === 1 ? rtrim($m[1], '/') : null;
        $shared !== null && realpath($shared) === realpath($this->projectDir) ? $say('OK', 'public_html/index.php loads this code tree')
            : $say('ERROR', 'public_html/index.php ' . ($index === '' ? 'is missing' : 'loads ' . ($shared ?? 'an unknown tree') . ', not ' . $this->projectDir) . ' (copy the release\'s public_html/index.php)');
        $editor = '/vendor/ckeditor5/emoji/16/en.json';
        is_file($public . $editor) && is_file($public . '/vendor/ckeditor5/ckeditor5.umd.js')
            ? $say('OK', 'public_html/vendor/ckeditor5 present')
            : $say('ERROR', 'public_html/vendor/ckeditor5 is missing or incomplete (copy the release\'s public_html/vendor/)');

        // Compiled assets (bin/console asset-map:compile): present and matching the current sources.
        $manifest = json_decode(is_file($public . '/assets/manifest.json') ? (string) file_get_contents($public . '/assets/manifest.json') : 'null', true);
        if (!is_array($manifest)) {
            $say('ERROR', 'compiled assets missing: run CTNLIST_INSTANCE_DIR=' . $this->instanceDir . ' bin/console asset-map:compile');
        } else {
            $stale = 0;
            $total = 0;
            foreach ($this->assets->allAssets() as $asset) {
                $total++;
                if (($manifest[$asset->logicalPath] ?? null) !== $asset->publicPath || !is_file($public . $asset->publicPath)) {
                    $stale++;
                }
            }
            $stale === 0 ? $say('OK', 'compiled assets current (' . $total . ' files)')
                : $say('ERROR', $stale . ' of ' . $total . ' compiled assets missing or out of date: run CTNLIST_INSTANCE_DIR=' . $this->instanceDir . ' bin/console asset-map:compile');
        }

        // Permissions: the code is read-only to the PHP user, the secrets file is not world-readable.
        if (is_writable($this->projectDir) || is_writable($this->projectDir . '/src')) {
            $say('WARN', 'the code tree ' . $this->projectDir . ' is writable by this user: run diagnose as the PHP-FPM user; the code should be read-only to it');
        }
        $env = rtrim($this->instanceDir, '/') . '/.env';
        if (is_file($env) && (fileperms($env) & 0o004) !== 0) {
            $say('WARN', $env . ' is readable by every user on the server: chmod 640 (owner and the PHP-FPM group only)');
        }
    }
}
