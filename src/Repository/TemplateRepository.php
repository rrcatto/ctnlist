<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/** Message templates (`templates`); `{content}` marks where the message goes. */
final class TemplateRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function count(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM templates');
    }

    /** @return list<array{t_id: int, t_name: string}> by name */
    public function page(int $offset, int $limit): array
    {
        return array_map(
            static fn(array $row): array => ['t_id' => (int) $row['t_id'], 't_name' => (string) $row['t_name']],
            $this->db->fetchAllAssociative('SELECT t_id, t_name FROM templates ORDER BY t_name LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset))
        );
    }

    /** @return list<array{t_id: int, t_name: string}> by name */
    public function choices(): array
    {
        return $this->page(0, PHP_INT_MAX);
    }

    public function create(string $name, string $html, string $text): int
    {
        return (int) $this->db->fetchOne('INSERT INTO templates (t_name, t_html, t_text) VALUES (?, ?, ?) RETURNING t_id', [$name, $html, $text]);
    }

    public function update(int $templateId, string $name, string $html, string $text): bool
    {
        return $this->db->executeStatement('UPDATE templates SET t_name = ?, t_html = ?, t_text = ? WHERE t_id = ?', [$name, $html, $text, $templateId]) === 1;
    }

    /** @return array{t_id: int, t_name: string, t_html: string, t_text: string}|null */
    public function find(int $templateId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT t_id, t_name, t_html, t_text FROM templates WHERE t_id = ?', [$templateId]);
        return $row === false ? null : [
            't_id' => (int) $row['t_id'],
            't_name' => (string) $row['t_name'],
            't_html' => (string) $row['t_html'],
            't_text' => (string) $row['t_text'],
        ];
    }
}
