<?php

declare(strict_types=1);

namespace App\Log;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Subscriber/message activity (`smlog`). The unique (subscriber UUID, MUID)
 * row is the once-only delivery guard for normal queue sends: it is created
 * when a subscriber is queued for a message and marked sent on delivery.
 * Rows exist only for a real subscriber and message.
 */
final class MessageLog
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Create the row if needed; a non-empty list shortcode replaces the
     * recorded list context. Returns false for an unknown subscriber/message.
     */
    public function ensure(string $subscriberUuid, string $muid, string $listShortcode = ''): bool
    {
        $subscriberUuid = self::uuid($subscriberUuid);
        if ($subscriberUuid === null || trim($muid) === '') {
            return false;
        }
        return $this->db->fetchOne(
            "INSERT INTO smlog (sml_s_uuid, sml_email, sml_muid, sml_list_shortcode)
             SELECT s.s_uuid, s.s_email, m.m_uniqid, :list
             FROM subscribers s, messages m
             WHERE s.s_uuid = :uuid AND m.m_uniqid = :muid
             ON CONFLICT (sml_s_uuid, sml_muid) DO UPDATE
             SET sml_list_shortcode = CASE WHEN EXCLUDED.sml_list_shortcode <> ''
                 THEN EXCLUDED.sml_list_shortcode ELSE smlog.sml_list_shortcode END
             RETURNING sml_id",
            ['uuid' => $subscriberUuid, 'muid' => trim($muid), 'list' => strtoupper(trim($listShortcode))]
        ) !== false;
    }

    public function markSent(string $subscriberUuid, string $muid, string $listShortcode = ''): void
    {
        if ($this->ensure($subscriberUuid, $muid, $listShortcode)) {
            $this->update($subscriberUuid, $muid, 'sml_date_sent = :now');
        }
    }

    public function record(string $subscriberUuid, string $muid, MessageActivity $activity): void
    {
        if ($this->ensure($subscriberUuid, $muid)) {
            $this->update($subscriberUuid, $muid, $activity->assignments());
        }
    }

    public function hasRecord(string $subscriberUuid, string $muid): bool
    {
        return $this->exists($subscriberUuid, $muid, '');
    }

    public function wasSent(string $subscriberUuid, string $muid): bool
    {
        return $this->exists($subscriberUuid, $muid, ' AND sml_date_sent IS NOT NULL');
    }

    /** The list context recorded for this delivery ('' when none). */
    public function listShortcode(string $subscriberUuid, string $muid): string
    {
        $subscriberUuid = self::uuid($subscriberUuid);
        if ($subscriberUuid === null) {
            return '';
        }
        $shortcode = $this->db->fetchOne(
            'SELECT sml_list_shortcode FROM smlog WHERE sml_s_uuid = ? AND sml_muid = ?',
            [$subscriberUuid, trim($muid)]
        );
        return $shortcode === false ? '' : strtoupper(trim((string) $shortcode));
    }

    /**
     * Messages delivered to the subscriber, newest first.
     *
     * @return list<array{m_uniqid: string, m_subject: string, sml_date_sent: string}>
     */
    public function history(string $subscriberUuid): array
    {
        $subscriberUuid = self::uuid($subscriberUuid);
        if ($subscriberUuid === null) {
            return [];
        }
        return array_map(static fn(array $row): array => [
            'm_uniqid' => (string) $row['m_uniqid'],
            'm_subject' => (string) $row['m_subject'],
            'sml_date_sent' => (string) $row['sml_date_sent'],
        ], $this->db->fetchAllAssociative(
            'SELECT m.m_uniqid, m.m_subject, sml.sml_date_sent
             FROM smlog sml JOIN messages m ON m.m_uniqid = sml.sml_muid
             WHERE sml.sml_s_uuid = ? AND sml.sml_date_sent IS NOT NULL
             ORDER BY sml.sml_date_sent DESC',
            [$subscriberUuid]
        ));
    }

    public function deleteForMessage(string $muid): void
    {
        $this->db->executeStatement('DELETE FROM smlog WHERE sml_muid = ?', [$muid]);
    }

    private function update(string $subscriberUuid, string $muid, string $assignments): void
    {
        $this->db->executeStatement(
            'UPDATE smlog SET ' . $assignments . ' WHERE sml_s_uuid = :uuid AND sml_muid = :muid',
            ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'uuid' => self::uuid($subscriberUuid), 'muid' => trim($muid)]
        );
    }

    private function exists(string $subscriberUuid, string $muid, string $condition): bool
    {
        $subscriberUuid = self::uuid($subscriberUuid);
        return $subscriberUuid !== null && $this->db->fetchOne(
            'SELECT 1 FROM smlog WHERE sml_s_uuid = ? AND sml_muid = ?' . $condition,
            [$subscriberUuid, trim($muid)]
        ) !== false;
    }

    private static function uuid(string $uuid): ?string
    {
        $uuid = strtolower(trim($uuid));
        return preg_match(self::UUID_PATTERN, $uuid) === 1 ? $uuid : null;
    }
}
