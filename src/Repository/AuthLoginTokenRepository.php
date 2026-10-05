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

    public function create(int $subscriberId, string $email, string $tokenHash, string $now, string $expiresAt, string $ip, string $userAgent, string $returnAction, ?int $returnMessageId, ?int $returnListId): void
    {
        $this->db->insert('auth_login_tokens', [
            'alt_s_id' => $subscriberId,
            'alt_email' => $email,
            'alt_token_hash' => $tokenHash,
            'alt_created_at' => $now,
            'alt_expires_at' => $expiresAt,
            'alt_requested_ip' => mb_substr($ip, 0, 45),
            'alt_user_agent' => mb_substr($userAgent, 0, 500),
            'alt_return_action' => $returnAction,
            'alt_return_m_id' => $returnMessageId,
            'alt_return_l_id' => $returnListId,
        ]);
    }

    public function delete(string $tokenHash): void
    {
        $this->db->executeStatement('DELETE FROM auth_login_tokens WHERE alt_token_hash = ?', [$tokenHash]);
    }

    public function countForEmailSince(string $email, string $since): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM auth_login_tokens WHERE alt_email = ? AND alt_created_at >= ?', [$email, $since]);
    }

    public function countForIpSince(string $ip, string $since): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM auth_login_tokens WHERE alt_requested_ip = ? AND alt_created_at >= ?', [$ip, $since]);
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
