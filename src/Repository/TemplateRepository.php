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
