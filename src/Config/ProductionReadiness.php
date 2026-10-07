<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Production configuration rules for `ctnlist:diagnose` (applied when
 * APP_ENV=prod, or with --production before an installation goes live).
 *
 * Pure checks over configuration values, so they can be tested with any
 * values. Findings name settings, never print a secret value.
 *
 * @phpstan-type Finding array{0: 'OK'|'WARN'|'ERROR', 1: string}
 * @phpstan-type Values array{environment: string, debug: bool, app_secret: string, base_url: string, mailer_dsn: string,
 *     suppression: string, connect_host: string, trusted_proxies: string, cattomail_secrets: array<string, string>, addresses: array<string, string>}
 */
final class ProductionReadiness
{
    /** Values found only in examples and documentation (compared lower-case, without punctuation). */
    private const PLACEHOLDERS = ['changeme', 'changethis', 'thistokenisnotsosecretchangeit', 'secret', 'password', 'example', 'test', 'todo', 'xxx', 'replaceme'];

    /** Hosts that never belong in a production base URL. */
    private const LOCAL_HOST = '/^(localhost|127\.\d+\.\d+\.\d+|0\.0\.0\.0|\[::1?\]|.+\.(test|local|localhost|invalid|example)|(.+\.)?example\.(com|org|net))$/i';

    /**
     * @param Values $values
     * @return list<Finding>
     */
    public static function check(array $values): array
    {
        $findings = [];

        $findings[] = self::finding($values['environment'] === 'prod', 'APP_ENV is prod', 'APP_ENV is "' . $values['environment'] . '": a production installation runs with APP_ENV=prod');
        $findings[] = self::finding(!$values['debug'], 'APP_DEBUG is off', 'APP_DEBUG is on: error pages would show stack traces, file paths and SQL (set APP_DEBUG=0)');
        $findings[] = self::finding(self::strongSecret($values['app_secret']), 'APP_SECRET looks random',
            'APP_SECRET is too short or a placeholder (generate: openssl rand -hex 32)');
        foreach ($values['cattomail_secrets'] as $name => $secret) {
            if (trim($secret) !== '') {
                $findings[] = self::finding(!self::isPlaceholder($secret), $name . ' is not a placeholder', $name . ' is a placeholder value: use the value catto-mail issued');
            }
        }

        $url = parse_url($values['base_url']);
        $host = is_array($url) ? (string) ($url['host'] ?? '') : '';
        $findings[] = self::finding(is_array($url) && ($url['scheme'] ?? '') === 'https' && $host !== '' && preg_match(self::LOCAL_HOST, $host) !== 1,
            'APP_BASE_URL is a public https address',
            'APP_BASE_URL "' . $values['base_url'] . '" is not a public https address: sign-in, unsubscribe and proof links in mail are built from it');

        $dsn = trim($values['mailer_dsn']);
        $findings[] = self::finding($dsn !== '' && !str_starts_with($dsn, 'null:'), 'MAILER_DSN is set to a real transport',
            'MAILER_DSN is ' . ($dsn === '' ? 'not set' : 'the null transport') . ': sign-in links and notifications cannot be sent');
        if ($dsn !== '') {
            $findings[] = self::finding(preg_match('/username:password@|@mail\.example\.|\bexample\.(com|org|net)\b/i', $dsn) !== 1, 'MAILER_DSN is not the example value',
                'MAILER_DSN still holds the example server or credentials from .env.example');
        }

        $findings[] = self::finding($values['suppression'] !== 'none', 'suppression provider: ' . ($values['suppression'] ?: 'banlist'),
            'SUPPRESSION_PROVIDER=none is for development only (refused outside APP_ENV dev/test)');
        if (trim($values['connect_host']) !== '') {
            $findings[] = ['WARN', 'CATTOMAIL_API_CONNECT_HOST is set: it is a development aid and is ignored outside APP_ENV dev/test; remove it'];
        }

        $proxies = array_filter(array_map('trim', explode(',', $values['trusted_proxies'])));
        $wide = array_intersect($proxies, ['0.0.0.0/0', '::/0']);
        $findings[] = self::finding($wide === [], 'TRUSTED_PROXIES ' . ($proxies === [] ? 'empty (clients connect directly)' : implode(', ', $proxies)),
            'TRUSTED_PROXIES trusts every address (' . implode(', ', $wide) . '): any client can forge X-Forwarded-For (rate limits, logs) and X-Forwarded-Proto; list only the reverse proxy', 'WARN');

        foreach ($values['addresses'] as $name => $address) {
            if (preg_match('/@(.+\.)?example\.(com|org|net)$/i', trim($address)) === 1) {
                $findings[] = ['WARN', $name . ' is still the example address (' . $address . ')'];
            }
        }
        return $findings;
    }

    /**
     * @param 'WARN'|'ERROR' $level
     * @return Finding
     */
    private static function finding(bool $ok, string $good, string $bad, string $level = 'ERROR'): array
    {
        return $ok ? ['OK', $good] : [$level, $bad];
    }

    public static function strongSecret(string $secret): bool
    {
        $secret = trim($secret);
        return strlen($secret) >= 32 && count(array_unique(str_split($secret))) >= 10 && !self::isPlaceholder($secret);
    }

    public static function isPlaceholder(string $value): bool
    {
        $plain = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
        if ($plain === '') {
            return true;
        }
        foreach (self::PLACEHOLDERS as $placeholder) {
            if ($plain === $placeholder || str_contains($plain, 'changeme') || str_contains($plain, 'notsosecret')) {
                return true;
            }
        }
        return false;
    }
}
