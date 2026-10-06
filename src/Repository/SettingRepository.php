<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Administrator overrides of .env settings, stored in `options` under the key
 * `setting:<ENV_NAME>` (secret values encrypted, see SettingsCipher).
 *
 * @phpstan-type StoredSetting array{value: string, secret: bool, updated_at: ?string, updated_by: ?string}
 */
final class SettingRepository
{
    private const PREFIX = 'setting:';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array<string, StoredSetting> by variable name; updated_by is the administrator's email */
    public function all(): array
    {
        $settings = [];
        $rows = $this->db->fetchAllAssociative(
            "SELECT o.o_key, o.o_value, o.o_secret, o.o_updated_at, s.s_email
             FROM options o LEFT JOIN subscribers s ON s.s_id = o.o_updated_by_s_id
             WHERE o.o_key LIKE 'setting:%'"
        );
        foreach ($rows as $row) {
            $settings[substr((string) $row['o_key'], strlen(self::PREFIX))] = [
                'value' => (string) $row['o_value'],
                'secret' => (bool) $row['o_secret'],
                'updated_at' => $row['o_updated_at'] === null ? null : (string) $row['o_updated_at'],
                'updated_by' => $row['s_email'] === null ? null : (string) $row['s_email'],
            ];
        }
        return $settings;
    }

    public function set(string $name, string $value, bool $secret, ?int $updatedBy): void
    {
        $this->db->executeStatement(
            'INSERT INTO options (o_key, o_value, o_secret, o_updated_at, o_updated_by_s_id) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (o_key) DO UPDATE SET o_value = EXCLUDED.o_value, o_secret = EXCLUDED.o_secret,
                 o_updated_at = EXCLUDED.o_updated_at, o_updated_by_s_id = EXCLUDED.o_updated_by_s_id',
            [self::PREFIX . $name, $value, $secret, $this->clock->now()->format('Y-m-d H:i:s'), $updatedBy],
            [2 => ParameterType::BOOLEAN]
        );
    }

    /** @return bool whether an override was removed */
    public function delete(string $name): bool
    {
        return $this->db->executeStatement('DELETE FROM options WHERE o_key = ?', [self::PREFIX . $name]) > 0;
    }
}
