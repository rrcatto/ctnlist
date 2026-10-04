<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/** Campaign archives (`archives`): immutable copies made when a message is first queued. */
final class ArchiveRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(string $subject, string $now): int
    {
        return (int) $this->db->fetchOne('INSERT INTO archives (a_subject, a_datecreated) VALUES (?, ?) RETURNING a_id', [$subject, $now]);
    }

    public function setHtml(int $archiveId, string $html): void
    {
        $this->db->executeStatement('UPDATE archives SET a_html = ? WHERE a_id = ?', [$html, $archiveId]);
    }
}
