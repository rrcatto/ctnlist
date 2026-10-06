<?php

declare(strict_types=1);

namespace App\Form\Settings;

use App\Config\RuntimeSettings;
use App\Config\SettingsCatalogue;

/**
 * Converts between the effective settings and a Settings form's data, and
 * from submitted form data to the input RuntimeSettings::save() takes.
 * Secret values never enter form data: passwords start empty.
 */
final class SettingsFormData
{
    /** @return array<string, mixed> the group's current values as form data */
    public static function initial(RuntimeSettings $settings, string $group): array
    {
        $data = [];
        foreach (SettingsCatalogue::GROUPS[$group]['settings'] as $name => $setting) {
            $default = $setting['default'] ?? '';
            if (($setting['secret'] ?? false) && $settings->secretProblem($name) !== null) {
                $value = '';
            } else {
                // Numbers and switches show the value in effect; text shows its default as a placeholder.
                $value = $settings->get($name, in_array($setting['type'], ['int', 'bool'], true) ? $default : '');
            }
            $data[$name] = match ($setting['type']) {
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'int' => is_numeric($value) ? (int) $value : null,
                'dsn' => self::dsn($value),
                default => $value,
            };
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data validated form data
     * @return array<string, mixed> input for RuntimeSettings::save()
     */
    public static function toInput(string $group, array $data): array
    {
        $input = [];
        foreach (SettingsCatalogue::GROUPS[$group]['settings'] as $name => $setting) {
            $value = $data[$name] ?? null;
            switch ($setting['type']) {
                case 'bool':
                    $input[$name] = $value ? 'true' : 'false';
                    break;
                case 'dsn':
                    $dsn = is_array($value) ? $value : [];
                    $input += [
                        'smtp_scheme' => (string) ($dsn['security'] ?? 'smtp'),
                        'smtp_host' => (string) ($dsn['host'] ?? ''),
                        'smtp_port' => isset($dsn['port']) ? (string) $dsn['port'] : '',
                        'smtp_username' => (string) ($dsn['username'] ?? ''),
                        'smtp_password' => (string) ($dsn['password'] ?? ''),
                        'smtp_options' => (string) ($dsn['options'] ?? ''),
                    ];
                    break;
                default:
                    $input[$name] = $value === null ? '' : (string) $value;
            }
        }
        return $input;
    }

    /** @return array{host: string, port: ?int, security: string, username: string, password: null, options: string, other: string, has_password: bool} */
    private static function dsn(string $dsn): array
    {
        $parts = RuntimeSettings::dsnParts($dsn);
        return [
            'host' => $parts['host'],
            'port' => $parts['port'] === '' ? null : (int) $parts['port'],
            'security' => $parts['scheme'],
            'username' => $parts['username'],
            'password' => null,
            'options' => $parts['options'],
            'other' => $parts['other'],
            'has_password' => $parts['has_password'],
        ];
    }
}
