<?php

declare(strict_types=1);

namespace App\CattoMail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Builds CattoMailConfig from the installation's .env (infrastructure, not Settings). */
final class CattoMailConfigFactory
{
    public function __construct(
        #[Autowire('%env(default::CATTOMAIL_API_BASE_URL)%')] private readonly ?string $baseUrl,
        #[Autowire('%env(default::CATTOMAIL_API_KEY)%')] private readonly ?string $apiKey,
        #[Autowire('%env(default::CATTOMAIL_WEBHOOK_SECRET)%')] private readonly ?string $webhookSecret,
        #[Autowire('%env(default::CATTOMAIL_WEBHOOK_SECRET_PREVIOUS)%')] private readonly ?string $previousWebhookSecret,
        #[Autowire('%env(default::CATTOMAIL_GLOBAL_OPTOUT_ENABLED)%')] private readonly ?string $globalOptOut,
        #[Autowire('%env(default::CATTOMAIL_CA_FILE)%')] private readonly ?string $caFile,
        #[Autowire('%env(default::CATTOMAIL_TIMEOUT_SECONDS)%')] private readonly ?string $timeout,
        #[Autowire('%env(default::CATTOMAIL_RECONCILE_AFTER_SECONDS)%')] private readonly ?string $reconcileAfter,
        #[Autowire('%env(default::CATTOMAIL_TRACK_OPENS)%')] private readonly ?string $trackOpens,
        #[Autowire('%env(default::CATTOMAIL_TRACK_CLICKS)%')] private readonly ?string $trackClicks,
        #[Autowire('%env(default::CATTOMAIL_API_CONNECT_HOST)%')] private readonly ?string $connectHost,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    public function __invoke(): CattoMailConfig
    {
        return new CattoMailConfig(
            trim((string) $this->baseUrl),
            trim((string) $this->apiKey),
            trim((string) $this->webhookSecret),
            trim((string) $this->previousWebhookSecret),
            filter_var($this->globalOptOut, FILTER_VALIDATE_BOOLEAN),
            trim((string) $this->caFile),
            is_numeric($this->timeout) ? max(1.0, (float) $this->timeout) : 15.0,
            is_numeric($this->reconcileAfter) ? max(60, (int) $this->reconcileAfter) : 900,
            filter_var($this->trackOpens, FILTER_VALIDATE_BOOLEAN),
            filter_var($this->trackClicks, FILTER_VALIDATE_BOOLEAN),
            // A development routing aid only; production always connects to the base URL's own host.
            in_array($this->environment, ['dev', 'test'], true) ? trim((string) $this->connectHost) : '',
        );
    }
}
