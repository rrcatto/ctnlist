<?php

declare(strict_types=1);

namespace App\Twig;

use Doctrine\DBAL\Connection;

/**
 * Counts shown in the administrator menu and toolbar (Twig: `counters.*`).
 * Each is queried on first use, so pages without the admin menu pay nothing.
 */
final class AdminCounters
{
    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** Subscribers with at least one confirmed, active list membership. */
    public function subscribers(): int
    {
        return $this->count('subscribers', 'SELECT COUNT(DISTINCT s.s_id)
             FROM subscribers s
             JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             JOIN lists l ON l.l_id = ls.ls_l_id
             WHERE ls.ls_confirmed = TRUE AND ls.ls_unsubscribed = FALSE AND l.l_active = TRUE');
    }

    /** Eligible subscribers who have interacted with a message. */
    public function activeReaders(): int
    {
        return $this->count('activeReaders', 'SELECT COUNT(DISTINCT s.s_id)
             FROM subscribers s
             JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             JOIN lists l ON l.l_id = ls.ls_l_id
             WHERE s.s_last_interacted IS NOT NULL
               AND ls.ls_confirmed = TRUE AND ls.ls_unsubscribed = FALSE AND l.l_active = TRUE');
    }

    public function queue(): int
    {
        return $this->count('queue', 'SELECT COUNT(*) FROM queue');
    }

    public function sendlog(): int
    {
        return $this->count('sendlog', 'SELECT COUNT(*) FROM sendlog');
    }

    public function messages(): int
    {
        return $this->count('messages', 'SELECT COUNT(*) FROM messages');
    }

    public function templates(): int
    {
        return $this->count('templates', 'SELECT COUNT(*) FROM templates');
    }

    private function count(string $key, string $sql): int
    {
        return $this->cache[$key] ??= (int) $this->db->fetchOne($sql);
    }
}
