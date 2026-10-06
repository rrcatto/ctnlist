<?php

declare(strict_types=1);

namespace App\CattoMail;

use Psr\Clock\ClockInterface;

/**
 * Verifies catto-mail webhook signatures (its public contract,
 * OpenAPI `webhooks.smarthostEvent`):
 *
 *   Smarthost-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>[,v1=<hex>]
 *
 * The HMAC is computed over the exact raw request bytes, before any JSON
 * parsing. A request is accepted when any v1 value matches the current or
 * (during a rotation overlap) the previous secret, compared in constant
 * time, and the timestamp is within 5 minutes of this server's clock.
 */
final class WebhookSignature
{
    public const HEADER = 'Smarthost-Signature';
    public const EVENT_ID_HEADER = 'Smarthost-Event-Id';
    public const TOLERANCE_SECONDS = 300;

    public const VALID = 'valid';
    public const MALFORMED = 'malformed';
    public const STALE = 'stale';
    public const MISMATCH = 'mismatch';
    public const NOT_CONFIGURED = 'not-configured';

    public function __construct(
        private readonly CattoMailConfig $config,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return string one of the constants above; only VALID may be processed */
    public function verify(string $rawBody, string $header): string
    {
        $secrets = $this->config->webhookSecrets();
        if ($secrets === []) {
            return self::NOT_CONFIGURED;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $item) {
            [$key, $value] = array_pad(explode('=', trim($item), 2), 2, '');
            if ($key === 't' && preg_match('/^\d{1,12}$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === []) {
            return self::MALFORMED;
        }
        if (abs($this->clock->now()->getTimestamp() - $timestamp) > self::TOLERANCE_SECONDS) {
            return self::STALE;
        }
        $matched = false;
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
            foreach ($signatures as $signature) {
                // Every comparison runs (no early exit), each in constant time.
                $matched = hash_equals($expected, $signature) || $matched;
            }
        }
        return $matched ? self::VALID : self::MISMATCH;
    }
}
