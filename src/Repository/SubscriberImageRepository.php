<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Profile pictures (`subscriber_images`, one PNG per subscriber). The bytes
 * cross the connection as base64 (encode()/decode() in SQL), so no driver
 * stream handling is involved.
 */
final class SubscriberImageRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array{png: string, updated_at: string}|null */
    public function find(int $subscriberId): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT encode(si_data, 'base64') AS encoded, si_updated_at FROM subscriber_images WHERE si_s_id = ?",
            [$subscriberId]
        );
        if ($row === false) {
            return null;
        }
        return ['png' => (string) base64_decode((string) $row['encoded']), 'updated_at' => (string) $row['si_updated_at']];
    }

    /** A short fingerprint of the stored picture (it changes with every new picture), or null; the bytes are not read. */
    public function version(int $subscriberId): ?string
    {
        $row = $this->db->fetchAssociative('SELECT si_updated_at, si_bytes FROM subscriber_images WHERE si_s_id = ?', [$subscriberId]);
        return $row === false ? null : substr(sha1($row['si_updated_at'] . '/' . $row['si_bytes']), 0, 12);
    }

    public function store(int $subscriberId, string $png, int $size): void
    {
        $this->db->executeStatement(
            "INSERT INTO subscriber_images (si_s_id, si_mime, si_size, si_bytes, si_data, si_updated_at)
             VALUES (:id, 'image/png', :size, :bytes, decode(:data, 'base64'), :at)
             ON CONFLICT (si_s_id) DO UPDATE SET si_size = EXCLUDED.si_size, si_bytes = EXCLUDED.si_bytes,
                 si_data = EXCLUDED.si_data, si_updated_at = EXCLUDED.si_updated_at",
            ['id' => $subscriberId, 'size' => $size, 'bytes' => strlen($png), 'data' => base64_encode($png),
                'at' => $this->clock->now()->format('Y-m-d H:i:s.u')]
        );
    }

    public function remove(int $subscriberId): bool
    {
        return $this->db->executeStatement('DELETE FROM subscriber_images WHERE si_s_id = ?', [$subscriberId]) > 0;
    }
}
