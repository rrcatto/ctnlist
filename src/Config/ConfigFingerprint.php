<?php

declare(strict_types=1);

namespace App\Config;

use App\CattoMail\CattoMailConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A short hash of the configuration that the web server (PHP-FPM) and the
 * cron worker must share: installation, environment, database, base URL,
 * catto-mail credentials and the settings key. The worker records its value
 * on every run; the Delivery page and `ctnlist:diagnose` compare it with
 * their own, so a cron entry that runs with another .env, another
 * database or a pool-level override is noticed.
 *
 * Secrets enter only as SHA-256 hashes, and the result is a truncated hash
 * of all of it: it reveals no value.
 */
final class ConfigFingerprint
{
    public function __construct(
        private readonly SiteConfig $site,
        private readonly CattoMailConfig $cattoMail,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.instance_dir%')] private readonly string $instanceDir,
        #[Autowire('%kernel.secret%')] #[\SensitiveParameter] private readonly string $appSecret,
        #[Autowire('%env(DB_HOST)%:%env(DB_PORT)%/%env(DB_NAME)%@%env(DB_USER)%')] private readonly string $database,
        #[Autowire('%env(default::APP_SETTINGS_KEY)%')] #[\SensitiveParameter] private readonly ?string $settingsKey = null,
    ) {
    }

    public function value(): string
    {
        $secret = static fn(string $value): string => $value === '' ? '' : hash('sha256', $value);
        return substr(hash('sha256', implode("\n", [
            $this->environment,
            rtrim($this->instanceDir, '/'),
            $this->site->instanceId,
            $this->site->baseUrl,
            $this->database,
            trim($this->cattoMail->baseUrl),
            $secret($this->cattoMail->apiKey()),
            $secret(implode(',', $this->cattoMail->webhookSecrets())),
            $secret(trim((string) $this->settingsKey)),
            $secret($this->appSecret),
        ])), 0, 12);
    }
}
