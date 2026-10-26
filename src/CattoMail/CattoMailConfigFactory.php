<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Util\Duration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds CattoMailConfig from the installation's .env and, for the address, the API key and the
 * webhook secret that .env leaves empty, from the connection made on the Delivery page
 * (ConnectionStore). Infrastructure, not editable Settings. The periods are
 * App\Util\Duration's (1y1w1d1h1m1s); one that is not a period gives the
 * default, and one below its minimum gives the minimum.
 */
final class CattoMailConfigFactory
{
    public function __construct(
        #[Autowire('%env(default::CATTOMAIL_API_BASE_URL)%')] private readonly ?string $baseUrl,
        #[Autowire('%env(default::CATTOMAIL_API_KEY)%')] private readonly ?string $apiKey,
        #[Autowire('%env(default::CATTOMAIL_WEBHOOK_SECRET)%')] private readonly ?string $webhookSecret,
        #[Autowire('%env(default::CATTOMAIL_WEBHOOK_SECRET_PREVIOUS)%')] private readonly ?string $previousWebhookSecret,
        #[Autowire('%env(default::CATTOMAIL_GLOBAL_OPTOUT_ENABLED)%')] private readonly ?string $globalOptOut,
        #[Autowire('%env(default::CATTOMAIL_CA_FILE)%')] private readonly ?string $caFile,
        #[Autowire('%env(default::CATTOMAIL_TIMEOUT)%')] private readonly ?string $timeout,
        #[Autowire('%env(default::CATTOMAIL_RECONCILE_AFTER)%')] private readonly ?string $reconcileAfter,
        #[Autowire('%env(default::CATTOMAIL_TRACK_OPENS)%')] private readonly ?string $trackOpens,
        #[Autowire('%env(default::CATTOMAIL_TRACK_CLICKS)%')] private readonly ?string $trackClicks,
        #[Autowire('%env(default::CATTOMAIL_API_CONNECT_HOST)%')] private readonly ?string $connectHost,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%env(default::CATTOMAIL_WEBHOOK_BODY_RETENTION)%')] private readonly ?string $webhookRetention = null,
        #[Autowire('%env(default::CATTOMAIL_DETAIL_RETENTION)%')] private readonly ?string $detailRetention = null,
        #[Autowire('%env(default::CATTOMAIL_WEBHOOK_URL)%')] private readonly ?string $webhookUrl = null,
        private readonly ?ConnectionStore $connection = null,
    ) {
    }

    public function __invoke(): CattoMailConfig
    {
        $development = in_array($this->environment, ['dev', 'test'], true);
        $stored = $this->connection?->credentials();
        $values = [];
        $sources = [];
        // .env wins for each value it sets; the connection supplies the rest.
        foreach (['base_url' => $this->baseUrl, 'api_key' => $this->apiKey, 'webhook_secret' => $this->webhookSecret] as $name => $env) {
            $env = trim((string) $env);
            $values[$name] = $env !== '' ? $env : trim($stored[$name] ?? '');
            if ($values[$name] !== '') {
                $sources[$name] = $env !== '' ? CattoMailConfig::SOURCE_ENV : CattoMailConfig::SOURCE_CONNECTION;
            }
        }
        return new CattoMailConfig(
            $values['base_url'],
            $values['api_key'],
            $values['webhook_secret'],
            trim((string) $this->previousWebhookSecret),
            filter_var($this->globalOptOut, FILTER_VALIDATE_BOOLEAN),
            trim((string) $this->caFile),
            Duration::setting($this->timeout, '15s', '1s'),
            Duration::setting($this->reconcileAfter, '15m', '1m'),
            filter_var($this->trackOpens, FILTER_VALIDATE_BOOLEAN),
            filter_var($this->trackClicks, FILTER_VALIDATE_BOOLEAN),
            // A development routing aid only; production always connects to the base URL's own host.
            $development ? trim((string) $this->connectHost) : '',
            // At least a week: a body may be needed to diagnose or reprocess a recent event.
            Duration::setting($this->webhookRetention, '90d', '1w'),
            Duration::setting($this->detailRetention, '1y', '30d'),
            $sources,
            (string) ($stored['problem'] ?? ''),
            // A development routing aid too; production always asks for webhooks at APP_BASE_URL.
            $development ? trim((string) $this->webhookUrl) : '',
        );
    }
}
