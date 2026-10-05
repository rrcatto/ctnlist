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

    public function count(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM archives');
    }

    /** @return list<array{a_id: int, a_subject: string, a_datecreated: string, a_viewed: int}> newest first */
    public function page(int $offset, int $limit): array
    {
        return array_map(static fn(array $row): array => [
            'a_id' => (int) $row['a_id'],
            'a_subject' => (string) $row['a_subject'],
            'a_datecreated' => (string) $row['a_datecreated'],
            'a_viewed' => (int) $row['a_viewed'],
        ], $this->db->fetchAllAssociative(
            'SELECT a_id, a_subject, a_datecreated, a_viewed FROM archives ORDER BY a_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        ));
    }

    /** @return array{a_id: int, a_subject: string, a_html: string, a_datecreated: string}|null */
    public function find(int $archiveId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT a_id, a_subject, a_html, a_datecreated FROM archives WHERE a_id = ?', [$archiveId]);
        return $row === false ? null : [
            'a_id' => (int) $row['a_id'],
            'a_subject' => (string) $row['a_subject'],
            'a_html' => (string) $row['a_html'],
            'a_datecreated' => (string) $row['a_datecreated'],
        ];
    }

    public function countView(int $archiveId): void
    {
        $this->db->executeStatement('UPDATE archives SET a_viewed = a_viewed + 1 WHERE a_id = ?', [$archiveId]);
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
