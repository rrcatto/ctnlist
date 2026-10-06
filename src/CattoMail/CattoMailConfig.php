<?php

declare(strict_types=1);

namespace App\CattoMail;

/**
 * Infrastructure configuration of the catto-mail integration, from the
 * installation's .env only (never from Settings, never rendered, never
 * logged). Changing CATTOMAIL_API_KEY in .env takes effect on the next
 * request or worker run.
 *
 * - CATTOMAIL_API_BASE_URL: the catto-mail HTTPS origin (the /v1 prefix is added here).
 * - CATTOMAIL_API_KEY: the client API key (Authorization: Bearer).
 * - CATTOMAIL_WEBHOOK_SECRET / CATTOMAIL_WEBHOOK_SECRET_PREVIOUS: the webhook
 *   endpoint's signing secret, and the rotated-out one during the overlap.
 * - CATTOMAIL_GLOBAL_OPTOUT_ENABLED: the catto-mail operator granted this
 *   client the global-opt-out capability.
 * - CATTOMAIL_CA_FILE: optional CA bundle for a development certificate.
 * - CATTOMAIL_API_CONNECT_HOST: development and test only: connect to this
 *   host (e.g. catto-mail's alias on the cattomail-dev network) for the base
 *   URL's host name; TLS is still verified for the base URL's host name.
 */
final class CattoMailConfig
{
    /** catto-mail contract limits (OpenAPI 1.0.0-draft.7). */
    public const MAX_BATCH_RECIPIENTS = 500;
    public const MAX_JOB_RECIPIENTS = 10000;
    public const MAX_VALIDATION_ADDRESSES = 10000;

    /** @var list<string> */
    private readonly array $webhookSecrets;

    public function __construct(
        public readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
        #[\SensitiveParameter] string $webhookSecret,
        #[\SensitiveParameter] string $previousWebhookSecret = '',
        public readonly bool $globalOptOutEnabled = false,
        public readonly string $caFile = '',
        public readonly float $timeoutSeconds = 15.0,
        public readonly int $reconcileAfterSeconds = 900,
        public readonly bool $trackOpens = false,
        public readonly bool $trackClicks = false,
        public readonly string $connectHost = '',
    ) {
        $this->webhookSecrets = array_values(array_filter([trim($webhookSecret), trim($previousWebhookSecret)], static fn(string $s): bool => $s !== ''));
    }

    /** API calls are possible (an HTTPS base URL and a key). */
    public function isConfigured(): bool
    {
        return $this->problem() === null;
    }

    /** Why the API cannot be used, for administrators (never contains a secret). */
    public function problem(): ?string
    {
        if (trim($this->baseUrl) === '' || trim($this->apiKey) === '') {
            return 'catto-mail is not configured (CATTOMAIL_API_BASE_URL and CATTOMAIL_API_KEY in .env).';
        }
        if (!str_starts_with(strtolower($this->baseUrl), 'https://')) {
            return 'CATTOMAIL_API_BASE_URL must be an https:// URL.';
        }
        return null;
    }

    /** The /v1 API root, without a trailing slash. */
    public function apiRoot(): string
    {
        $base = rtrim(trim($this->baseUrl), '/');
        return str_ends_with($base, '/v1') ? $base : $base . '/v1';
    }

    public function apiKey(): string
    {
        return trim($this->apiKey);
    }

    /** @return list<string> the current and (during rotation) previous webhook signing secrets */
    public function webhookSecrets(): array
    {
        return $this->webhookSecrets;
    }

    /** Keep secrets out of var_dump(), debug output and serialised logs. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => $this->apiKey === '' ? '' : '[redacted]', 'webhookSecrets' => count($this->webhookSecrets) . ' configured',
            'globalOptOutEnabled' => $this->globalOptOutEnabled];
    }

    public function __serialize(): array
    {
        throw new \LogicException('CattoMailConfig holds secrets and is not serialisable.');
    }
}
