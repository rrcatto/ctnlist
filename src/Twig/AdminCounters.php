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

    /** Every subscriber record, as the subscriber list shows them by default. */
    public function subscribers(): int
    {
        return $this->count('subscribers', 'SELECT COUNT(*) FROM subscribers');
    }

    /** Subscribers who have interacted with a message, as Active readers shows them by default. */
    public function activeReaders(): int
    {
        return $this->count('activeReaders', 'SELECT COUNT(*) FROM subscribers WHERE s_last_interacted IS NOT NULL');
    }

    /** Form submissions refused as automated, still in the Site Log (Flagged activity). */
    public function flagged(): int
    {
        return $this->count('flagged', 'SELECT COUNT(*) FROM sitelog WHERE stl_flag IS NOT NULL');
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
