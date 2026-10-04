<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Authenticated browser sessions (`auth_sessions`), identified by the SHA-256
 * hash of the token in the auth cookie. Independent of the PHP session.
 */
final class AuthSessionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(int $subscriberId, string $tokenHash, string $now, string $expiresAt, string $ip, string $userAgent): void
    {
        $this->db->insert('auth_sessions', [
            'as_s_id' => $subscriberId,
            'as_token_hash' => $tokenHash,
            'as_created_at' => $now,
            'as_expires_at' => $expiresAt,
            'as_last_seen_at' => $now,
            'as_ip_address' => mb_substr($ip, 0, 45),
            'as_user_agent' => mb_substr($userAgent, 0, 500),
        ]);
    }

    /** @return array{as_id: int, as_s_id: int, as_last_seen_at: string}|null */
    public function findActive(string $tokenHash, string $now): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT as_id, as_s_id, as_last_seen_at
             FROM auth_sessions
             WHERE as_token_hash = ? AND as_revoked_at IS NULL AND as_expires_at > ?',
            [$tokenHash, $now]
        );
        if ($row === false) {
            return null;
        }
        return [
            'as_id' => (int) $row['as_id'],
            'as_s_id' => (int) $row['as_s_id'],
            'as_last_seen_at' => (string) $row['as_last_seen_at'],
        ];
    }

    public function touch(int $id, string $now): void
    {
        $this->db->executeStatement('UPDATE auth_sessions SET as_last_seen_at = ? WHERE as_id = ?', [$now, $id]);
    }

    public function revoke(int $id, string $now): void
    {
        $this->db->executeStatement(
            'UPDATE auth_sessions SET as_revoked_at = ? WHERE as_id = ? AND as_revoked_at IS NULL',
            [$now, $id]
        );
    }

    public function revokeByTokenHash(string $tokenHash, string $now): void
    {
        $this->db->executeStatement(
            'UPDATE auth_sessions SET as_revoked_at = ? WHERE as_token_hash = ? AND as_revoked_at IS NULL',
            [$now, $tokenHash]
        );
    }
}
