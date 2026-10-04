<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/** Campaign messages (`messages`), publicly identified by their MUID (`m_uniqid`). */
final class MessageRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findMuidById(int $id): ?string
    {
        $muid = $this->db->fetchOne('SELECT m_uniqid FROM messages WHERE m_id = ?', [$id]);
        return $muid === false ? null : (string) $muid;
    }
}
