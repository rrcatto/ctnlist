<?php

declare(strict_types=1);

namespace App\Config;

use App\Repository\SettingRepository;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;

/**
 * The selected .env settings (SettingsCatalogue), resolved as
 * database override → .env → built-in default. The database holds overrides
 * only; .env is never written, and an override equal to the .env value is
 * removed so later .env changes apply again.
 *
 * Secret overrides (SMTP credentials) are encrypted with SettingsCipher and
 * decrypted only when trusted server code asks for them (get()); they are not
 * part of environment(), and a secret that cannot be decrypted raises
 * SettingsCipherException instead of silently falling back.
 *
 * @phpstan-import-type Setting from SettingsCatalogue
 * @phpstan-import-type StoredSetting from SettingRepository
 */
final class RuntimeSettings
{
    public const SOURCE_DATABASE = 'database';
    public const SOURCE_ENV = 'env';
    public const SOURCE_DEFAULT = 'default';

    /** @var array<string, StoredSetting>|null stored overrides (secrets still encrypted), read once per request */
    private ?array $overrides = null;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly SettingsCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The effective value: the override, else .env, else $default.
     *
     * @throws SettingsCipherException for a stored secret that cannot be decrypted
     */
    public function get(string $name, string $default = ''): string
    {
        $stored = $this->overrides()[$name] ?? null;
        $value = match (true) {
            $stored === null => $this->envValue($name),
            $stored['secret'] => $this->cipher->decrypt($stored['value']),
            default => $stored['value'],
        };
        return $value !== '' ? $value : $default;
    }

    public function int(string $name, int $default): int
    {
        $value = $this->get($name);
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<string, mixed> the process environment with the non-secret overrides applied (for SiteConfig) */
    public function environment(): array
    {
        $plain = [];
        foreach ($this->overrides() as $name => $stored) {
            if (!$stored['secret']) {
                $plain[$name] = $stored['value'];
            }
        }
        return $plain + $_ENV + $_SERVER;
    }

    public function envValue(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }

    public function isOverridden(string $name): bool
    {
        return isset($this->overrides()[$name]);
    }

    /** Where the effective value comes from: database, env or default. */
    public function source(string $name): string
    {
        return match (true) {
            $this->isOverridden($name) => self::SOURCE_DATABASE,
            $this->envValue($name) !== '' => self::SOURCE_ENV,
            default => self::SOURCE_DEFAULT,
        };
    }

    /** @return array{at: ?string, by: ?string}|null when and by whom the override was stored */
    public function overrideInfo(string $name): ?array
    {
        $stored = $this->overrides()[$name] ?? null;
        return $stored === null ? null : ['at' => $stored['updated_at'], 'by' => $stored['updated_by']];
    }

    /** Why a stored secret override cannot be used, or null. */
    public function secretProblem(string $name): ?string
    {
        $stored = $this->overrides()[$name] ?? null;
        if ($stored === null || !$stored['secret']) {
            return null;
        }
        try {
            $this->cipher->decrypt($stored['value']);
            return null;
        } catch (SettingsCipherException $e) {
            return $e->getMessage();
        }
    }

    /** Why secrets cannot be stored (APP_SETTINGS_KEY), or null. */
    public function cipherProblem(): ?string
    {
        return $this->cipher->problem();
    }

    /**
     * Validate and store one group of the Settings page. Each value equal to
     * .env (or, for numbers and switches .env leaves unset, the
     * default) removes that override; any other value is stored, secrets
     * encrypted.
     *
     * @param array<string, mixed> $input
     * @return list<string> the names whose override was set or removed
     * @throws \InvalidArgumentException for an unknown group
     * @throws SettingsCipherException when a secret must be stored but APP_SETTINGS_KEY is unusable
     */
    public function save(string $group, array $input, ?int $updatedBy): array
    {
        $settings = self::group($group);
        $values = [];
        foreach ($settings as $name => $setting) {
            $values[$name] = $this->validated($name, $setting, $input);
        }
        $changed = [];
        foreach ($values as $name => $value) {
            $setting = $settings[$name];
            $inherited = $this->inherited($name, $setting);
            $equal = $value === self::normalise($setting, $inherited);
            if ($equal) {
                if ($this->settings->delete($name)) {
                    $changed[] = $name;
                }
                continue;
            }
            $secret = $setting['secret'] ?? false;
            $current = $this->overrides()[$name] ?? null;
            if ($current !== null && !$secret && $current['value'] === $value) {
                continue;
            }
            if ($current !== null && $secret && $this->secretProblem($name) === null && $this->get($name) === $value) {
                continue;
            }
            $this->settings->set($name, $secret ? $this->cipher->encrypt($value) : $value, $secret, $updatedBy);
            $changed[] = $name;
        }
        $this->overrides = null;
        if ($changed !== []) {
            // Names only: values (secrets in particular) are never logged.
            $this->logger->notice('Settings "{group}" saved by subscriber {by}: {names}.', ['group' => $group, 'by' => $updatedBy ?? '-', 'names' => implode(', ', $changed)]);
        }
        return $changed;
    }

    /** Remove one override, so .env (or the default) applies again. */
    public function resetSetting(string $name, ?int $updatedBy): void
    {
        if (!isset(SettingsCatalogue::names()[$name])) {
            throw new \InvalidArgumentException('Unknown setting.');
        }
        if ($this->settings->delete($name)) {
            $this->logger->notice('Setting {name} reset to .env by subscriber {by}.', ['name' => $name, 'by' => $updatedBy ?? '-']);
        }
        $this->overrides = null;
    }

    /** Remove every override of a group. */
    public function resetGroup(string $group, ?int $updatedBy): void
    {
        foreach (array_keys(self::group($group)) as $name) {
            $this->resetSetting($name, $updatedBy);
        }
    }

    /**
     * MAILER_DSN as form fields; the password is never returned, only whether one is set.
     *
     * @return array{scheme: string, host: string, port: string, username: string, has_password: bool, options: string, other: string}
     */
    public static function dsnParts(string $dsn): array
    {
        $parts = parse_url($dsn);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (!is_array($parts) || !in_array($scheme, ['smtp', 'smtps'], true)) {
            // Not an SMTP URL (e.g. null:// in tests): shown as-is, minus any credentials.
            return ['scheme' => 'smtp', 'host' => '', 'port' => '', 'username' => '', 'has_password' => false, 'options' => '',
                'other' => $dsn === '' ? '' : (string) preg_replace('#//[^@/]*@#', '//***@', $dsn)];
        }
        return [
            'scheme' => $scheme,
            'host' => (string) ($parts['host'] ?? ''),
            'port' => isset($parts['port']) ? (string) $parts['port'] : '',
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'has_password' => ($parts['pass'] ?? '') !== '',
            'options' => (string) ($parts['query'] ?? ''),
            'other' => '',
        ];
    }

    /** @return array<string, Setting> */
    private static function group(string $group): array
    {
        return SettingsCatalogue::GROUPS[$group]['settings'] ?? throw new \InvalidArgumentException('Unknown settings group.');
    }

    /** @return array<string, StoredSetting> */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }
        try {
            $stored = $this->settings->all();
        } catch (DbalException $e) {
            // Before migrations, or with the database unavailable: .env alone applies.
            $this->logger->warning('Settings overrides unavailable: {message}', ['message' => $e->getMessage()]);
            return $this->overrides = [];
        }
        return $this->overrides = array_intersect_key($stored, SettingsCatalogue::names());
    }

    /**
     * The value the setting falls back to without an override: .env, or for
     * numbers and switches that .env leaves unset, the default
     * (text stays blank: blank already means the default).
     *
     * @param Setting $setting
     */
    private function inherited(string $name, array $setting): string
    {
        $env = $this->envValue($name);
        return $env === '' && in_array($setting['type'], ['int', 'bool'], true) ? ($setting['default'] ?? '') : $env;
    }

    /**
     * The value to store from the submitted input: trimmed and normalised,
     * with defaults for empty numbers and switches. Input rules (email, URL,
     * ranges, host and port syntax) are enforced by the Settings form
     * (App\Form\Settings\SettingConstraints) before save() is called.
     *
     * @param Setting $setting
     * @param array<string, mixed> $input
     */
    private function validated(string $name, array $setting, array $input): string
    {
        $raw = $input[$name] ?? '';
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        if ($value === '' && isset($setting['default']) && in_array($setting['type'], ['int', 'bool'], true)) {
            $value = $setting['default'];
        }
        return match ($setting['type']) {
            'bool', 'int' => self::normalise($setting, $value),
            'dsn' => $this->dsnFromInput($input),
            default => $value,
        };
    }

    /** @param array<string, mixed> $input */
    private function dsnFromInput(array $input): string
    {
        $field = static fn(string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $host = $field('smtp_host');
        if ($host === '') {
            // A non-SMTP DSN (e.g. null:// in tests) cannot be shown as fields: keep it.
            $current = $this->get('MAILER_DSN');
            return self::dsnParts($current)['other'] !== '' ? $current : '';
        }
        $port = $field('smtp_port') === '' ? '' : (string) (int) $field('smtp_port');
        $options = ltrim($field('smtp_options'), '?');
        $username = $field('smtp_username');
        $password = is_scalar($input['smtp_password'] ?? null) ? (string) $input['smtp_password'] : '';
        if ($password === '' && $username !== '') {
            $current = parse_url($this->get('MAILER_DSN'));
            $password = is_array($current) ? rawurldecode((string) ($current['pass'] ?? '')) : '';
        }
        $credentials = $username === '' ? '' : rawurlencode($username) . ($password !== '' ? ':' . rawurlencode($password) : '') . '@';
        $scheme = $field('smtp_scheme') === 'smtps' ? 'smtps' : 'smtp';
        return $scheme . '://' . $credentials . $host . ($port !== '' ? ':' . $port : '') . ($options !== '' ? '?' . $options : '');
    }

    /** @param Setting $setting */
    private static function normalise(array $setting, string $value): string
    {
        $value = trim($value);
        return match ($setting['type']) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'int' => is_numeric($value) ? (string) (int) $value : $value,
            default => $value,
        };
    }
}
