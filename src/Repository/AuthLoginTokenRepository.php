<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/** One-time magic-link tokens (`auth_login_tokens`); only SHA-256 hashes are stored. */
final class AuthLoginTokenRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Atomically mark an unused, unexpired token as used, so a link cannot be
     * redeemed twice even by concurrent requests.
     *
     * @return array{subscriber_id: int, return_action: string, return_message_id: int, return_list_id: int}|null
     */
    public function claim(string $tokenHash, string $now): ?array
    {
        $row = $this->db->fetchAssociative(
            'UPDATE auth_login_tokens
             SET alt_used_at = :now
             WHERE alt_token_hash = :hash AND alt_used_at IS NULL AND alt_expires_at > :now
             RETURNING alt_s_id, alt_return_action, alt_return_m_id, alt_return_l_id',
            ['hash' => $tokenHash, 'now' => $now]
        );
        if ($row === false) {
            return null;
        }
        return [
            'subscriber_id' => (int) $row['alt_s_id'],
            'return_action' => (string) $row['alt_return_action'],
            'return_message_id' => (int) $row['alt_return_m_id'],
            'return_list_id' => (int) $row['alt_return_l_id'],
        ];
    }
}
