<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Mailing lists (`lists`).
 *
 * @phpstan-type MailingList array{l_id: int, l_shortcode: string, l_name: string, l_description: string, l_active: bool, l_system: bool}
 */
final class ListRepository
{
    private const COLUMNS = 'l_id, l_shortcode, l_name, l_description, l_active, l_system';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<MailingList> system lists first, then by name */
    public function all(bool $activeOnly = true): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM lists'
            . ($activeOnly ? ' WHERE l_active = TRUE' : '')
            . ' ORDER BY l_system DESC, l_name ASC'
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** @return MailingList|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM lists WHERE l_id = ?', [$id]);
        return $row === false ? null : $this->hydrate($row);
    }

    /** @return MailingList|null */
    public function findByShortcode(string $shortcode): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM lists WHERE l_shortcode = ?', [strtoupper(trim($shortcode))]);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findShortcodeById(int $id): ?string
    {
        $shortcode = $this->db->fetchOne('SELECT l_shortcode FROM lists WHERE l_id = ?', [$id]);
        return $shortcode === false ? null : (string) $shortcode;
    }

    public function create(string $shortcode, string $name, string $description): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO lists (l_shortcode, l_name, l_description) VALUES (?, ?, ?) RETURNING l_id',
            [$shortcode, $name, $description]
        );
    }

    /** Delete a non-system list; returns false when it was a system list or missing. */
    public function deleteCustom(int $id): bool
    {
        return $this->db->executeStatement('DELETE FROM lists WHERE l_id = ? AND l_system = FALSE', [$id]) === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @return MailingList
     */
    private function hydrate(array $row): array
    {
        return [
            'l_id' => (int) $row['l_id'],
            'l_shortcode' => (string) $row['l_shortcode'],
            'l_name' => (string) $row['l_name'],
            'l_description' => (string) $row['l_description'],
            'l_active' => (bool) $row['l_active'],
            'l_system' => (bool) $row['l_system'],
        ];
    }
}
