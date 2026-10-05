<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/** Key/value application state (`options`), e.g. the queue's SendQueue/CurrentlySending flags. */
final class OptionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->db->fetchOne('SELECT o_value FROM options WHERE o_key = ?', [$key]);
        return $value === false || $value === null ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->db->executeStatement(
            'INSERT INTO options (o_key, o_value) VALUES (?, ?) ON CONFLICT (o_key) DO UPDATE SET o_value = EXCLUDED.o_value',
            [$key, $value]
        );
    }
}
