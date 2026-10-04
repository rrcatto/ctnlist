<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Subscriber identities (`subscribers`). A subscriber row is an identity only;
 * list consent lives in `list_subscribers`.
 *
 * @phpstan-type Identity array{s_id: int, s_uuid: string, s_email: string, s_fname: string, s_lname: string}
 */
final class SubscriberRepository
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return Identity|null */
    public function findIdentityById(int $id): ?array
    {
        return $this->identity($this->db->fetchAssociative(
            'SELECT s_id, s_uuid, s_email, s_fname, s_lname FROM subscribers WHERE s_id = ?',
            [$id]
        ));
    }

    /** @return Identity|null */
    public function findIdentityByUuid(string $uuid): ?array
    {
        $uuid = strtolower(trim($uuid));
        if (!preg_match(self::UUID_PATTERN, $uuid)) {
            return null;
        }
        return $this->identity($this->db->fetchAssociative(
            'SELECT s_id, s_uuid, s_email, s_fname, s_lname FROM subscribers WHERE s_uuid = ?',
            [$uuid]
        ));
    }

    public function exists(int $id): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM subscribers WHERE s_id = ?', [$id]) !== false;
    }

    /** @return list<array{s_id: int, s_email: string}> the first subscribers by email, for pickers */
    public function emails(int $limit): array
    {
        return array_map(
            static fn(array $row): array => ['s_id' => (int) $row['s_id'], 's_email' => (string) $row['s_email']],
            $this->db->fetchAllAssociative('SELECT s_id, s_email FROM subscribers ORDER BY s_email LIMIT ' . max(1, $limit))
        );
    }

    public function recordLogin(int $id, string $at, string $ip): void
    {
        $this->db->executeStatement(
            'UPDATE subscribers SET s_last_login_at = ?, s_last_login_ip = ? WHERE s_id = ?',
            [$at, mb_substr($ip, 0, 45), $id]
        );
    }

    /**
     * @param array<string, mixed>|false $row
     * @return Identity|null
     */
    private function identity(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        return [
            's_id' => (int) $row['s_id'],
            's_uuid' => (string) $row['s_uuid'],
            's_email' => (string) $row['s_email'],
            's_fname' => trim((string) $row['s_fname']),
            's_lname' => trim((string) $row['s_lname']),
        ];
    }
}
