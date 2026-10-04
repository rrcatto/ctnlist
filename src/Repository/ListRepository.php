<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/** Mailing lists (`lists`). */
final class ListRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findShortcodeById(int $id): ?string
    {
        $shortcode = $this->db->fetchOne('SELECT l_shortcode FROM lists WHERE l_id = ?', [$id]);
        return $shortcode === false ? null : (string) $shortcode;
    }
}
