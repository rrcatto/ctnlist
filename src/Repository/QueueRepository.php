<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * The delivery queue (`queue`): one row per subscriber/message, unique.
 *
 * @phpstan-type QueueRow array{q_id: int, q_muid: string, q_s_uuid: string, q_email: string, q_list_shortcode: string}
 * @phpstan-type Candidate array{s_id: int, s_uuid: string, s_email: string, s_last_interacted: ?string, s_priority: int, s_emailsleft: int, l_shortcode?: string}
 */
final class QueueRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function count(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue');
    }

    /** Add a row; false when the subscriber is already queued for the message. */
    public function add(string $muid, string $subscriberUuid, string $subject, string $email, string $listShortcode, ?string $lastInteracted, int $messagePriority, int $subscriberPriority): bool
    {
        return $this->db->executeStatement(
            'INSERT INTO queue (q_muid, q_subject, q_s_uuid, q_email, q_list_shortcode, q_last_interacted, q_mpriority, q_spriority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (q_muid, q_s_uuid) DO NOTHING',
            [$muid, $subject, $subscriberUuid, $email, strtoupper(trim($listShortcode)), $lastInteracted, $messagePriority, $subscriberPriority]
        ) === 1;
    }

    public function isQueued(string $muid, string $subscriberUuid): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM queue WHERE q_muid = ? AND q_s_uuid = ?', [$muid, $subscriberUuid]) !== false;
    }

    public function delete(int $queueId): bool
    {
        return $this->db->executeStatement('DELETE FROM queue WHERE q_id = ?', [$queueId]) === 1;
    }

    public function clear(): int
    {
        return $this->db->executeStatement('DELETE FROM queue');
    }

    /**
     * Next rows to send, in v5 order: message priority, engaged subscribers
     * first (most recent interaction), subscriber priority, then age.
     *
     * @return list<QueueRow>
     */
    public function nextBatch(string $muid, int $limit): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT q_id, q_muid, q_s_uuid, q_email, q_list_shortcode FROM queue'
            . ($muid !== '' ? ' WHERE q_muid = :muid' : '')
            . ' ORDER BY q_mpriority DESC, (q_last_interacted IS NULL) ASC, q_last_interacted DESC, q_spriority DESC, q_id ASC
              LIMIT ' . max(1, $limit),
            $muid !== '' ? ['muid' => $muid] : []
        );
        return array_map(static fn(array $row): array => [
            'q_id' => (int) $row['q_id'],
            'q_muid' => (string) $row['q_muid'],
            'q_s_uuid' => (string) $row['q_s_uuid'],
            'q_email' => (string) $row['q_email'],
            'q_list_shortcode' => (string) $row['q_list_shortcode'],
        ], $rows);
    }

    /**
     * Eligible subscribers not yet queued or logged for the message: the union
     * of its selected lists, one row per subscriber (system lists first, then
     * by shortcode, for the list context), engaged subscribers first.
     *
     * @return list<Candidate>
     */
    public function eligibleForMessage(int $messageId, string $muid, int $limit): array
    {
        return $this->candidates($this->db->fetchAllAssociative(
            'WITH eligible AS (
                SELECT s.s_id, s.s_uuid, s.s_email, s.s_last_interacted, s.s_priority, s.s_emailsleft, l.l_shortcode,
                       ROW_NUMBER() OVER (PARTITION BY s.s_id ORDER BY l.l_system DESC, l.l_shortcode ASC) AS audience_row
                FROM subscribers s
                JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
                JOIN lists l ON l.l_id = ls.ls_l_id
                JOIN message_lists ml ON ml.ml_l_id = l.l_id
                LEFT JOIN smlog sml ON sml.sml_s_uuid = s.s_uuid AND sml.sml_muid = :muid
                WHERE ml.ml_m_id = :mid
                  AND ls.ls_confirmed = TRUE AND ls.ls_unsubscribed = FALSE AND l.l_active = TRUE
                  AND sml.sml_id IS NULL
                  AND NOT EXISTS (SELECT 1 FROM queue q WHERE q.q_muid = :muid AND q.q_s_uuid = s.s_uuid)
            )
            SELECT s_id, s_uuid, s_email, s_last_interacted, s_priority, s_emailsleft, l_shortcode
            FROM eligible
            WHERE audience_row = 1
            ORDER BY (s_last_interacted IS NULL) ASC, s_last_interacted DESC, s_priority DESC, s_email ASC
            LIMIT ' . max(1, min(5000, $limit)),
            ['muid' => $muid, 'mid' => $messageId]
        ));
    }

    /**
     * Subscribers eligible for at least one of the messages and not yet
     * queued or logged for it (the advanced-queue rotation candidates).
     *
     * @param list<int> $messageIds
     * @return list<Candidate>
     */
    public function eligibleForAnyMessage(array $messageIds, int $limit): array
    {
        if ($messageIds === []) {
            return [];
        }
        return $this->candidates($this->db->fetchAllAssociative(
            'SELECT s.s_id, s.s_uuid, s.s_email, s.s_last_interacted, s.s_priority, s.s_emailsleft
             FROM subscribers s
             JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             JOIN lists l ON l.l_id = ls.ls_l_id
             JOIN message_lists ml ON ml.ml_l_id = l.l_id
             JOIN messages m ON m.m_id = ml.ml_m_id
             WHERE ml.ml_m_id IN (?)
               AND ls.ls_confirmed = TRUE AND ls.ls_unsubscribed = FALSE AND l.l_active = TRUE
               AND NOT EXISTS (SELECT 1 FROM smlog x WHERE x.sml_s_uuid = s.s_uuid AND x.sml_muid = m.m_uniqid)
               AND NOT EXISTS (SELECT 1 FROM queue x WHERE x.q_s_uuid = s.s_uuid AND x.q_muid = m.m_uniqid)
             GROUP BY s.s_id
             ORDER BY (s.s_last_interacted IS NULL) ASC, s.s_last_interacted DESC, s.s_priority DESC, s.s_email ASC
             LIMIT ' . max(1, $limit),
            [$messageIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Candidate>
     */
    private function candidates(array $rows): array
    {
        return array_map(static function (array $row): array {
            $candidate = [
                's_id' => (int) $row['s_id'],
                's_uuid' => (string) $row['s_uuid'],
                's_email' => (string) $row['s_email'],
                's_last_interacted' => $row['s_last_interacted'] === null ? null : (string) $row['s_last_interacted'],
                's_priority' => (int) $row['s_priority'],
                's_emailsleft' => (int) $row['s_emailsleft'],
            ];
            if (isset($row['l_shortcode'])) {
                $candidate['l_shortcode'] = (string) $row['l_shortcode'];
            }
            return $candidate;
        }, $rows);
    }
}
