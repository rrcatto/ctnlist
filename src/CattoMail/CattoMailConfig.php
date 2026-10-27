<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Util\Duration;

/**
 * Infrastructure configuration of the catto-mail integration (never rendered
 * or logged; only the address is an editable Setting). The API key and the
 * webhook signing secret come from the installation's .env, or, for each one
 * .env leaves empty, from the connection an administrator made (ConnectionStore:
 * the encrypted Settings store). The address is the mail service's
 * (owner decision 2026-10-27): the Settings store's (set on the Settings page,
 * or by a connection) wins over .env, except that a key set in .env takes the
 * address .env sets with it. A change takes effect on the next request or worker
 * run.
 *
 * - CATTOMAIL_API_BASE_URL: the mail service's HTTPS address (its origin; the /v1 prefix is added here).
 * - CATTOMAIL_API_KEY: the client API key (Authorization: Bearer).
 * - CATTOMAIL_WEBHOOK_SECRET / CATTOMAIL_WEBHOOK_SECRET_PREVIOUS: the webhook
 *   endpoint's signing secret, and the rotated-out one during the overlap.
 * - CATTOMAIL_GLOBAL_OPTOUT_ENABLED: the catto-mail operator granted this
 *   client the global-opt-out capability.
 * - CATTOMAIL_CA_FILE: optional CA bundle for a development certificate.
 * - CATTOMAIL_API_CONNECT_HOST: development and test only: connect to this
 *   host (e.g. catto-mail's alias on the cattomail-dev network), optionally
 *   host:port, for the base URL's host name; TLS is still verified for the
 *   base URL's host name.
 * - CATTOMAIL_WEBHOOK_URL: development and test only: the webhook address a
 *   connection asks catto-mail to deliver to, when catto-mail cannot reach
 *   APP_BASE_URL (e.g. http://ctnlist-web/cattomail/webhook on that network).
 * - CATTOMAIL_TIMEOUT (15s, at least 1s): the API request timeout.
 * - CATTOMAIL_RECONCILE_AFTER (15m, at least 1m): jobs without a final
 *   webhook for this long are polled.
 * - CATTOMAIL_WEBHOOK_BODY_RETENTION (90d, at least 1w): raw bodies of processed
 *   webhook events are removed after this; the event rows stay.
 * - CATTOMAIL_DETAIL_RETENTION (1y, at least 30d): rendered content of refused jobs
 *   and validation results superseded by newer ones are removed after this.
 * The four are periods (App\Util\Duration: 1y1w1d1h1m1s).
 */
final class CattoMailConfig
{
    /** catto-mail contract limits (OpenAPI 1.0.0-draft.11). */
    public const MAX_BATCH_RECIPIENTS = 500;
    public const MAX_JOB_RECIPIENTS = 10000;
    public const MAX_VALIDATION_ADDRESSES = 10000;
    /** catto-mail refuses request bodies over 10 MiB (413). */
    public const MAX_REQUEST_BYTES = 10 * 1024 * 1024;
    /** A recipient batch is uploaded before its JSON would pass this (headroom below MAX_REQUEST_BYTES). */
    public const MAX_BATCH_BYTES = 8 * 1024 * 1024;

    /** Where a value comes from: the installation's .env, the connection made here, or (the address) the Settings page. */
    public const SOURCE_ENV = 'env';
    public const SOURCE_CONNECTION = 'connection';
    public const SOURCE_SETTINGS = 'settings';

    /** @var list<string> */
    private readonly array $webhookSecrets;
    private readonly bool $currentSecret;
    private readonly bool $previousSecret;
    public readonly Duration $timeout;
    public readonly Duration $reconcileAfter;
    public readonly Duration $webhookBodyRetention;
    public readonly Duration $detailRetention;

    public function __construct(
        public readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
        #[\SensitiveParameter] string $webhookSecret,
        #[\SensitiveParameter] string $previousWebhookSecret = '',
        public readonly bool $globalOptOutEnabled = false,
        public readonly string $caFile = '',
        ?Duration $timeout = null,
        ?Duration $reconcileAfter = null,
        public readonly bool $trackOpens = false,
        public readonly bool $trackClicks = false,
        public readonly string $connectHost = '',
        ?Duration $webhookBodyRetention = null,
        ?Duration $detailRetention = null,
        /** @var array{base_url?: string, api_key?: string, webhook_secret?: string} where each value comes from (SOURCE_*) */
        private readonly array $sources = [],
        /** Why the stored connection cannot be read (APP_SETTINGS_KEY), '' when it can or there is none. */
        public readonly string $connectionProblem = '',
        public readonly string $webhookUrl = '',
    ) {
        $this->timeout = $timeout ?? Duration::parse('15s');
        $this->reconcileAfter = $reconcileAfter ?? Duration::parse('15m');
        $this->webhookBodyRetention = $webhookBodyRetention ?? Duration::parse('90d');
        $this->detailRetention = $detailRetention ?? Duration::parse('1y');
        $this->webhookSecrets = array_values(array_filter([trim($webhookSecret), trim($previousWebhookSecret)], static fn(string $s): bool => $s !== ''));
        $this->currentSecret = trim($webhookSecret) !== '';
        $this->previousSecret = trim($previousWebhookSecret) !== '';
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
            return $this->connectionProblem !== ''
                ? 'catto-mail is not configured: the stored connection cannot be read (' . $this->connectionProblem . '). Restore the APP_SETTINGS_KEY it was saved with, or connect again on the Delivery page.'
                : 'catto-mail is not configured: connect the mail service (Settings › Set up sending), or set CATTOMAIL_API_BASE_URL and CATTOMAIL_API_KEY in .env.';
        }
        if (!str_starts_with(strtolower($this->baseUrl), 'https://')) {
            return 'The catto-mail address must be an https:// URL' . ($this->source('base_url') === self::SOURCE_ENV ? ' (CATTOMAIL_API_BASE_URL).' : '.');
        }
        return null;
    }

    /**
     * Where the address ('base_url'), the key ('api_key') or the signing secret ('webhook_secret') comes
     * from: SOURCE_ENV, SOURCE_CONNECTION, SOURCE_SETTINGS (the address only), or '' when it is not set at all.
     */
    public function source(string $value): string
    {
        return $this->sources[$value] ?? '';
    }

    /** The API is used with the address and key of the connection made on the Delivery page. */
    public function usesConnection(): bool
    {
        return $this->source('base_url') === self::SOURCE_CONNECTION && $this->source('api_key') === self::SOURCE_CONNECTION;
    }

    /**
     * The .env settings that take precedence over a connection (names only): the key and the
     * secret, and the address when the key comes with it. An address in .env alone is only the
     * default of the mail service's address, which a connection uses.
     *
     * @return list<string>
     */
    public function envSettings(): array
    {
        $names = ['api_key' => 'CATTOMAIL_API_KEY', 'webhook_secret' => 'CATTOMAIL_WEBHOOK_SECRET'];
        if ($this->source('api_key') === self::SOURCE_ENV) {
            $names = ['base_url' => 'CATTOMAIL_API_BASE_URL'] + $names;
        }
        return array_values(array_filter($names, fn(string $name, string $value): bool => $this->source($value) === self::SOURCE_ENV, ARRAY_FILTER_USE_BOTH));
    }

    /** The mail service's address as a person gives it (its origin, without the API's /v1), or ''. */
    public function address(): string
    {
        $base = rtrim(trim($this->baseUrl), '/');
        return str_ends_with($base, '/v1') ? substr($base, 0, -3) : $base;
    }

    /** The /v1 API root, without a trailing slash. */
    public function apiRoot(): string
    {
        return self::rootOf($this->baseUrl);
    }

    /** The /v1 API root of a catto-mail address (its origin, or its API base URL). */
    public static function rootOf(string $baseUrl): string
    {
        $base = rtrim(trim($baseUrl), '/');
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

    public function hasWebhookSecret(): bool
    {
        return $this->currentSecret;
    }

    /** A rotated-out secret is still accepted (the rotation overlap). */
    public function hasPreviousWebhookSecret(): bool
    {
        return $this->previousSecret;
    }

    /** Keep secrets out of var_dump(), debug output and serialised logs. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => $this->apiKey === '' ? '' : '[redacted]', 'webhookSecrets' => count($this->webhookSecrets) . ' configured',
            'globalOptOutEnabled' => $this->globalOptOutEnabled, 'sources' => $this->sources];
    }

    public function __serialize(): array
    {
        throw new \LogicException('CattoMailConfig holds secrets and is not serialisable.');
    }
}
