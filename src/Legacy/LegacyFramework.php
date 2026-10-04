<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
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
    public const DESIGN_MAIN = 'unify-main-template.html';
    public const DESIGN_STORE = 'unify-custom-store.html';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.instance_dir%')] private readonly string $instanceDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
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
        $fat->set('UI', $this->projectDir . '/templates/legacy/');
        $fat->set('LOGS', $this->instanceDir . '/logs/');
        // Compiled templates go in the instance's writable var/ directory, so
        // public_html can stay read-only.
        $fat->set('TEMP', $this->instanceDir . '/var/tmp/');
        $fat->set('TZ', (string) $envOr('APP_TIMEZONE', 'Africa/Johannesburg'));
        $fat->set('ESCAPE', false);

        $designConfig = $this->projectDir . '/config/legacy/design.ini';
        if (!is_file($designConfig)) {
            throw new RuntimeException('Shared design configuration file not found: ' . $designConfig);
        }
        $fat->config($designConfig, true);

        // Safe, non-secret values made available to F3 templates and legacy controllers.
        $hive = [
            'BaseURL' => rtrim((string) $envOr('APP_BASE_URL', 'http://localhost/'), '/') . '/',
            'ListName' => (string) $envOr('APP_LIST_NAME', 'ctnlist'),
            'Domain' => (string) $envOr('APP_DOMAIN', 'localhost'),
            'Organisation' => (string) $envOr('APP_ORGANISATION', ''),
            'Telephone' => (string) $envOr('APP_TELEPHONE', ''),
            'WhatsApp' => (string) $envOr('APP_WHATSAPP', ''),
            'WhatsAppURL' => (string) $envOr('APP_WHATSAPP_URL', ''),
            'StreetAddress' => (string) $envOr('APP_STREET_ADDRESS', ''),
            'AboutUs' => (string) $envOr('APP_ABOUT_US', ''),
            'AdvertiseURL' => (string) $envOr('APP_ADVERTISE_URL', ''),
            'FacebookPageURL' => (string) $envOr('APP_FACEBOOK_URL', ''),
            'TwitterURL' => (string) $envOr('APP_X_URL', ''),
            'StoreURL' => (string) $envOr('APP_STORE_URL', ''),
            'BookingURL' => (string) $envOr('APP_BOOKING_URL', ''),
            'ContactURL' => (string) $envOr('APP_CONTACT_URL', '{BaseURL}contact-form/{suid}/{muid}'),
            'API' => (string) $envOr('APP_INSTANCE_ID', ''),
            'AdminEmail' => (string) $envOr('MAIL_ADMIN_ADDRESS', $envOr('APP_ADMIN_EMAIL', '')),
            'AdminName' => (string) $envOr('MAIL_ADMIN_NAME', 'Administrator'),
            'FromAddress' => (string) $envOr('MAIL_FROM_ADDRESS', ''),
            'FromName' => (string) $envOr('MAIL_FROM_NAME', ''),
            'BounceAddress' => (string) $envOr('MAIL_BOUNCE_ADDRESS', ''),
            'UnsubscribeAddress' => (string) $envOr('MAIL_UNSUBSCRIBE_ADDRESS', ''),
            'OrderEmail' => (string) $envOr('CONTACT_MAIL_ADDRESS', $envOr('MAIL_ADMIN_ADDRESS', '')),
            'OrderFormName' => (string) $envOr('CONTACT_MAIL_NAME', $envOr('APP_ORGANISATION', 'ctnlist')),
            'OrderFormSubject' => (string) $envOr('CONTACT_MAIL_SUBJECT', 'Your message has been received'),
            'OrderLogFilename' => (string) $envOr('CONTACT_LOG_FILE', $this->instanceDir . '/logs/contact.log'),
            'SubscriptionMessage' => (string) $envOr('SUBSCRIPTION_MESSAGE', 'Manage your list subscriptions: {preferences}'),
            'SubscriptionConfirmMessage' => (string) $envOr('SUBSCRIPTION_CONFIRM_MESSAGE', 'Confirm your list subscription: {confirm}'),
            'SubscriptionConfirmLevel' => (int) $envOr('SUBSCRIPTION_CONFIRM_LEVEL', 0),
            'SubscriptionConfirmAmount' => (int) $envOr('SUBSCRIPTION_CONFIRM_AMOUNT', 0),
            'BounceLimit' => (int) $envOr('MAIL_BOUNCE_LIMIT', 2),
            'EmailsPerMinute' => (int) $envOr('MAIL_RATE_PER_MINUTE', 13),
            'TestEmail' => (string) $envOr('MAIL_TEST_ADDRESS', $envOr('APP_ADMIN_EMAIL', '')),
            'archive' => $envBool('APP_ARCHIVE_ENABLED') ? 1 : 0,
        ];
        foreach ($hive as $key => $value) {
            $fat->set($key, $value);
        }
        $smtpServers = json_decode((string) $envOr('MAIL_SMTP_SERVERS_JSON', '[]'), true);
        if (!is_array($smtpServers)) {
            $smtpServers = [];
        }
        $fat->set('smtp_servers', $smtpServers);
        $fat->set('default_smtp_server', $smtpServers[0] ?? []);
        $syncServers = json_decode((string) $envOr('SYNC_DATABASES_JSON', '[]'), true);
        $fat->set('dbservers', is_array($syncServers) ? $syncServers : []);
        $fat->set('today', date('Y.m.d H:i:s'));
        $fat->set('version', '5.0.2');

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
