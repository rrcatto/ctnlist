<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/** Send Log report queries (writes go through App\Log\SendLog). */
final class SendLogRepository
{
    /** Workflow types offered even before the first email of that type. */
    public const KNOWN_TYPES = [
        'MESSAGE', 'PROOF', 'RESEND', 'FORWARD-MESSAGE', 'FORWARD-NOTIFICATION',
        'SUBSCRIBE', 'CONFIRM', 'UNSUBSCRIBE', 'UPDATE-PROFILE', 'UPDATE-USER',
        'MAGIC-LINK', 'CONTACT',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** v5 filter: type is a prefix, email a case-insensitive substring. */
    public function count(string $email, string $type): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM sendlog WHERE sl_type LIKE ? AND LOWER(sl_email) LIKE LOWER(?)',
            [Like::startsWith($type), Like::contains($email)]
        );
    }

    /** @return list<array<string, mixed>> newest first */
    public function page(string $email, string $type, int $offset, int $limit): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT sl_datesent, sl_type, sl_email, sl_list_shortcode, sl_subject, sl_s_uuid FROM sendlog
             WHERE sl_type LIKE ? AND LOWER(sl_email) LIKE LOWER(?)
             ORDER BY sl_datesent DESC, sl_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [Like::startsWith($type), Like::contains($email)]
        );
    }

    /** @return list<array{sl_type: string, sl_total: int}> */
    public function totalsByType(): array
    {
        return array_map(static fn(array $row): array => ['sl_type' => (string) $row['sl_type'], 'sl_total' => (int) $row['sl_total']],
            $this->db->fetchAllAssociative('SELECT sl_type, COUNT(*) AS sl_total FROM sendlog GROUP BY sl_type ORDER BY sl_type'));
    }

    /**
     * Totals per type for each month, newest month first.
     *
     * @return array<string, list<array{sl_type: string, sl_total: int}>> keyed "YYYY/MM"
     */
    public function totalsByMonth(): array
    {
        $months = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT EXTRACT(YEAR FROM sl_datesent)::INTEGER AS y, EXTRACT(MONTH FROM sl_datesent)::INTEGER AS m, sl_type, COUNT(*) AS sl_total
             FROM sendlog GROUP BY 1, 2, sl_type ORDER BY y DESC, m DESC, sl_type'
        );
        foreach ($rows as $row) {
            $months[sprintf('%04d/%02d', $row['y'], $row['m'])][] = ['sl_type' => (string) $row['sl_type'], 'sl_total' => (int) $row['sl_total']];
        }
        return $months;
    }

    /** @return list<string> the known types plus any other type in the log, sorted */
    public function types(): array
    {
        $types = array_values(array_unique(array_merge(self::KNOWN_TYPES, array_map('strval', $this->db->fetchFirstColumn('SELECT DISTINCT sl_type FROM sendlog')))));
        sort($types, SORT_STRING);
        return $types;
    }
}
