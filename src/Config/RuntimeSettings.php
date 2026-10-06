<?php

declare(strict_types=1);

namespace App\Config;

use App\Mail\SmtpServer;
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
 * @phpstan-type ServerRow array{index: string, active: bool, host: string, port: string, security: string, username: string, has_password: bool, batchsize: string, delay: string, sendrate: string}
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
     * .env (or, for numbers, switches and server lists .env leaves unset, the
     * default) removes that override; any other value is stored, secrets
     * encrypted.
     *
     * @param array<string, mixed> $input
     * @return list<string> the names whose override was set or removed
     * @throws \InvalidArgumentException naming the first invalid field
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
            $equal = $setting['type'] === 'servers'
                ? self::canonicalServers($value) === self::canonicalServers($inherited)
                : $value === self::normalise($setting, $inherited);
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

    /**
     * MAIL_SMTP_SERVERS_JSON entries as editable rows, in failover order, without passwords.
     *
     * @return list<ServerRow>
     */
    public static function serverRows(string $json): array
    {
        $rows = [];
        foreach (self::serverEntries($json) as $index => $entry) {
            $fields = self::serverFields($entry);
            $rows[] = [
                'index' => (string) $index,
                'active' => (int) ($entry['active'] ?? 0) === 1,
                'host' => $fields['host'],
                'port' => $fields['port'],
                'security' => $fields['enc'],
                'username' => $fields['user'],
                'has_password' => $fields['pass'] !== '',
                'batchsize' => (string) ($entry['batchsize'] ?? 600),
                'delay' => (string) ($entry['delay'] ?? 10),
                'sendrate' => isset($entry['sendrate']) ? (string) $entry['sendrate'] : '',
            ];
        }
        return $rows;
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
     * numbers, switches and server lists that .env leaves unset, the default
     * (text stays blank: blank already means the default).
     *
     * @param Setting $setting
     */
    private function inherited(string $name, array $setting): string
    {
        $env = $this->envValue($name);
        return $env === '' && in_array($setting['type'], ['int', 'bool', 'servers'], true) ? ($setting['default'] ?? '') : $env;
    }

    /**
     * @param Setting $setting
     * @param array<string, mixed> $input
     */
    private function validated(string $name, array $setting, array $input): string
    {
        $raw = $input[$name] ?? '';
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        $label = $setting['label'];
        $optional = ($setting['optional'] ?? false) || isset($setting['default']);
        if ($value === '' && isset($setting['default']) && in_array($setting['type'], ['int', 'bool'], true)) {
            $value = $setting['default'];
        }

        switch ($setting['type']) {
            case 'bool':
                return self::normalise($setting, $value);
            case 'int':
                if (!preg_match('/^-?\d+$/', $value) || (int) $value < ($setting['min'] ?? PHP_INT_MIN)) {
                    throw new \InvalidArgumentException(sprintf('%s must be a whole number%s.', $label, isset($setting['min']) ? ' of at least ' . $setting['min'] : ''));
                }
                return (string) (int) $value;
            case 'email':
                if ($value === '' ? !$optional : filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException(sprintf('%s must be a valid email address.', $label));
                }
                return $value;
            case 'url':
                if ($value !== '' && (filter_var($value, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $value))) {
                    throw new \InvalidArgumentException(sprintf('%s must be a full web address (https://…).', $label));
                }
                return $value;
            case 'dsn':
                return $this->dsnFromInput($input);
            case 'servers':
                return $this->serversFromInput($name, $input);
            default:
                if ($value === '' && !$optional) {
                    throw new \InvalidArgumentException(sprintf('%s is required.', $label));
                }
                if (mb_strlen($value) > 2000) {
                    throw new \InvalidArgumentException(sprintf('%s is too long.', $label));
                }
                return $value;
        }
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
        self::validateHost($host, 'the main SMTP server');
        $port = self::validatePort($field('smtp_port'), 'the main SMTP server');
        $options = ltrim($field('smtp_options'), '?');
        if (preg_match('/[\s#]/', $options) === 1) {
            throw new \InvalidArgumentException('SMTP options may not contain spaces or #.');
        }
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

    /**
     * The failover servers from their form rows (`servers[i][…]`), in the
     * order given by `position`. Blank rows and rows marked for removal are
     * dropped; an empty password keeps the server's current one.
     *
     * @param array<string, mixed> $input
     */
    private function serversFromInput(string $name, array $input): string
    {
        $rows = is_array($input['servers'] ?? null) ? $input['servers'] : [];
        $current = self::serverEntries($this->get($name));
        $entries = [];
        foreach (array_values($rows) as $n => $row) {
            if (!is_array($row)) {
                continue;
            }
            $field = static fn(string $key): string => is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
            $host = $field('host');
            if ($host === '' || $field('remove') === '1') {
                continue;
            }
            $label = 'failover server ' . $host;
            self::validateHost($host, $label);
            $security = $field('security');
            if (!in_array($security, ['', 'tls', 'ssl'], true)) {
                throw new \InvalidArgumentException(sprintf('Choose the security mode for %s.', $label));
            }
            $user = $field('username');
            $pass = is_scalar($row['password'] ?? null) ? (string) $row['password'] : '';
            $original = $field('index') !== '' ? ($current[(int) $field('index')] ?? null) : null;
            if ($pass === '' && $user !== '' && $original !== null) {
                $pass = self::serverFields($original)['pass'];
            }
            $entry = [
                'active' => $field('active') === '1' ? 1 : 0,
                'host' => $host,
                'port' => (int) (self::validatePort($field('port'), $label) ?: ($security === 'ssl' ? 465 : 25)),
                'user' => $user,
                'pass' => $user === '' ? '' : $pass,
                'enc' => $security,
                'batchsize' => self::boundedInt($field('batchsize'), 1, 600, 'Batch size for ' . $label),
                'delay' => self::boundedInt($field('delay'), 0, 10, 'Delay for ' . $label),
            ];
            if ($field('sendrate') !== '') {
                $entry['sendrate'] = self::boundedInt($field('sendrate'), 0, 0, 'Messages per minute for ' . $label);
            }
            $position = $field('position');
            $entries[] = [is_numeric($position) ? (int) $position : PHP_INT_MAX, $n, $entry];
        }
        usort($entries, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return (string) json_encode(array_column($entries, 2), JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array<string, mixed>> */
    private static function serverEntries(string $json): array
    {
        $decoded = json_decode($json === '' ? '[]' : $json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * Host/port/user/pass/enc of an entry given either as fields or as `dsn`.
     *
     * @param array<string, mixed> $entry
     * @return array{host: string, port: string, user: string, pass: string, enc: string}
     */
    private static function serverFields(array $entry): array
    {
        $dsn = trim((string) ($entry['dsn'] ?? ''));
        if ($dsn === '') {
            return [
                'host' => (string) ($entry['host'] ?? ''),
                'port' => isset($entry['port']) ? (string) $entry['port'] : '',
                'user' => (string) ($entry['user'] ?? ''),
                'pass' => (string) ($entry['pass'] ?? ''),
                'enc' => in_array($entry['enc'] ?? '', ['tls', 'ssl'], true) ? (string) $entry['enc'] : '',
            ];
        }
        $parts = parse_url($dsn) ?: [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        return [
            'host' => (string) ($parts['host'] ?? ''),
            'port' => isset($parts['port']) ? (string) $parts['port'] : '',
            'user' => rawurldecode((string) ($parts['user'] ?? '')),
            'pass' => rawurldecode((string) ($parts['pass'] ?? '')),
            'enc' => strtolower((string) ($parts['scheme'] ?? '')) === 'smtps' ? 'ssl' : (($query['require_tls'] ?? '') === 'true' ? 'tls' : ''),
        ];
    }

    /**
     * What a server list means to the mailer (transport, batching, rate,
     * active), so lists written differently but meaning the same compare equal.
     *
     * @return list<array{bool, string, int, int, int}>
     */
    private static function canonicalServers(string $json): array
    {
        $canonical = [];
        foreach (self::serverEntries($json) as $entry) {
            try {
                $server = SmtpServer::fromConfig($entry, -1);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $canonical[] = [(int) ($entry['active'] ?? 0) === 1, $server->dsn, $server->batchSize, $server->delay, $server->sendRate];
        }
        return $canonical;
    }

    private static function validateHost(string $host, string $label): void
    {
        if (preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) !== 1 && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException(sprintf('Enter a valid host name for %s.', $label));
        }
    }

    private static function validatePort(string $port, string $label): string
    {
        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            throw new \InvalidArgumentException(sprintf('The port for %s must be between 1 and 65535.', $label));
        }
        return $port === '' ? '' : (string) (int) $port;
    }

    private static function boundedInt(string $value, int $min, int $default, string $label): int
    {
        if ($value === '') {
            return $default;
        }
        if (!preg_match('/^\d+$/', $value) || (int) $value < $min) {
            throw new \InvalidArgumentException(sprintf('%s must be a whole number of at least %d.', $label, $min));
        }
        return (int) $value;
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
