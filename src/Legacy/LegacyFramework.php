<?php

declare(strict_types=1);

namespace App\Legacy;

use App\Config\SiteConfig;
use Base;
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the Fat-Free hive for the legacy routes (temporary migration bridge).
 *
 * Symfony owns the request: it has already loaded the instance .env, started
 * the session and installed its error handler. F3's own error/exception
 * handlers are removed again, and reroutes and HTTP errors become exceptions
 * that LegacyBridge turns into Symfony responses.
 */
final class LegacyFramework
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.instance_dir%')] private readonly string $instanceDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SiteConfig $site,
    ) {
    }

    public function create(): Base
    {
        $errorLevel = error_reporting();
        $fat = Base::instance();
        restore_error_handler();
        restore_exception_handler();
        error_reporting($errorLevel);

        // Without HALT, Base::error() would carry on executing the route after
        // the handler returns, so both hooks throw instead.
        $fat->set('HALT', false);
        $fat->set('ONERROR', static function (Base $fat): never {
            throw new LegacyHttpError((int) $fat->get('ERROR.code'), (string) $fat->get('ERROR.text'));
        });
        $fat->set(Csrf::MANAGER, $this->csrfTokenManager);
        $fat->set('ONREROUTE', static function (string $url, bool $permanent = false): never {
            throw new LegacyRedirect($url, $permanent);
        });

        $envOr = static function (string $name, mixed $default = null): mixed {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            return $value !== false && $value !== '' ? $value : $default;
        };
        $envBool = static fn(string $name, bool $default = false): bool => filter_var(
            (string) $envOr($name, $default ? 'true' : 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        $fat->set('CACHE', false);
        $fat->set('DEBUG', $envBool('APP_DEBUG') ? 3 : 0);
        $fat->set('LOGS', $this->instanceDir . '/logs/');
        // Content strings are compiled by F3's Template class into the
        // instance's writable var/ directory; public_html stays read-only.
        $fat->set('TEMP', $this->instanceDir . '/var/tmp/');
        $fat->set('TZ', (string) $envOr('APP_TIMEZONE', 'Africa/Johannesburg'));
        $fat->set('ESCAPE', false);

        $designConfig = $this->projectDir . '/config/legacy/design.ini';
        if (!is_file($designConfig)) {
            throw new RuntimeException('Shared design configuration file not found: ' . $designConfig);
        }
        $fat->config($designConfig, true);

        // Site settings under the hive keys the legacy controllers and content use.
        $site = $this->site;
        $hive = [
            'BaseURL' => $site->baseUrl,
            'ListName' => $site->listName,
            'Domain' => $site->domain,
            'Organisation' => $site->organisation,
            'Telephone' => $site->telephone,
            'WhatsApp' => $site->whatsApp,
            'WhatsAppURL' => $site->whatsAppUrl,
            'StreetAddress' => $site->streetAddress,
            'AboutUs' => $site->aboutUs,
            'AdvertiseURL' => $site->advertiseUrl,
            'FacebookPageURL' => $site->facebookUrl,
            'TwitterURL' => $site->xUrl,
            'StoreURL' => $site->storeUrl,
            'BookingURL' => $site->bookingUrl,
            'ContactURL' => $site->contactUrl,
            'API' => $site->instanceId,
            'AdminEmail' => $site->adminEmail,
            'AdminName' => $site->adminName,
            'FromAddress' => $site->fromAddress,
            'FromName' => $site->fromName,
            'BounceAddress' => $site->bounceAddress,
            'UnsubscribeAddress' => $site->unsubscribeAddress,
            'OrderEmail' => $site->contactEmail,
            'OrderFormName' => $site->contactName,
            'OrderFormSubject' => $site->contactSubject,
            'OrderLogFilename' => $site->contactLogFile,
            'SubscriptionMessage' => $site->subscriptionMessage,
            'SubscriptionConfirmMessage' => $site->subscriptionConfirmMessage,
            'SubscriptionConfirmLevel' => $site->subscriptionConfirmLevel,
            'SubscriptionConfirmAmount' => $site->subscriptionConfirmAmount,
            'BounceLimit' => $site->bounceLimit,
            'EmailsPerMinute' => $site->emailsPerMinute,
            'TestEmail' => $site->testEmail,
            'archive' => $site->archiveEnabled ? 1 : 0,
            'smtp_servers' => $site->smtpServers,
            'default_smtp_server' => $site->smtpServers[0] ?? [],
            'dbservers' => $site->syncDatabases,
            'today' => date('Y.m.d H:i:s'),
            'version' => SiteConfig::VERSION,
        ];
        foreach ($hive as $key => $value) {
            $fat->set($key, $value);
        }

        $dbDriver = strtolower((string) $envOr('DB_DRIVER', 'pgsql'));
        if ($dbDriver !== 'pgsql') {
            throw new RuntimeException('ctnlist 5.0 Phase 3A requires PostgreSQL.');
        }
        $dbHost = (string) $envOr('DB_HOST', '127.0.0.1');
        $dbPort = (int) $envOr('DB_PORT', 5432);
        $dbName = (string) $envOr('DB_NAME');
        $dbUser = (string) $envOr('DB_USER');
        $dbPass = (string) $envOr('DB_PASS');
        $dbSslMode = (string) $envOr('DB_SSLMODE', 'prefer');
        $dbDsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};sslmode={$dbSslMode}";
        $dbOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_PERSISTENT => true,
        ];
        $dbPDO = new \DB\SQL($dbDsn, $dbUser, $dbPass, $dbOptions);
        $fat->set('dbdriver', 'pgsql');
        $fat->set('dbPDO', $dbPDO);

        // The global suppression database is intentionally separate and shared. Its
        // configuration may live anywhere outside the source tree; config/phinx in
        // the shared tree is the default location.
        //
        // SUPPRESSION_PROVIDER=none disables the global suppression lookup. It is
        // only accepted in development, pending the catto-mail smarthost replacing
        // the banlist database as the suppression source.
        $suppressionProvider = strtolower((string) $envOr('SUPPRESSION_PROVIDER', 'banlist'));
        if ($suppressionProvider === 'none') {
            if ($this->environment !== 'dev') {
                throw new RuntimeException('SUPPRESSION_PROVIDER=none is only permitted when APP_ENV=dev.');
            }
            $fat->set('gdbPDO', null);
        } elseif ($suppressionProvider === 'banlist') {
            $banEnvDirectory = rtrim(
                (string) $envOr('GDB_ENV_DIRECTORY', $this->projectDir . '/config/phinx'),
                DIRECTORY_SEPARATOR
            );
            $banEnvName = basename((string) $envOr('GDB_ENV_FILE', 'ban.env'));
            $banEnvFile = $banEnvDirectory . DIRECTORY_SEPARATOR . $banEnvName;
            if (!is_file($banEnvFile)) {
                throw new RuntimeException('Global suppression database configuration file not found: ' . $banEnvFile);
            }
            (new Dotenv())->load($banEnvFile);
            $gdbDriver = strtolower((string) $envOr('GDB_DRIVER', 'pgsql'));
            if ($gdbDriver !== 'pgsql') {
                throw new RuntimeException('The global suppression database must use PostgreSQL.');
            }
            $gdbDsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                (string) $envOr('GDB_HOST', '127.0.0.1'),
                (int) $envOr('GDB_PORT', 5432),
                (string) $envOr('GDB_NAME', 'banlist'),
                (string) $envOr('GDB_SSLMODE', 'prefer')
            );
            $gdbPDO = new \DB\SQL(
                $gdbDsn,
                (string) $envOr('GDB_USER'),
                (string) $envOr('GDB_PASS'),
                $dbOptions
            );
            $fat->set('gdbdriver', 'pgsql');
            $fat->set('gdbPDO', $gdbPDO);
        } else {
            throw new RuntimeException('Unknown SUPPRESSION_PROVIDER: ' . $suppressionProvider);
        }

        return $fat;
    }
}
