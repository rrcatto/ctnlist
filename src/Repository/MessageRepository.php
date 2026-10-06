<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Campaign messages (`messages`, `message_lists`), publicly identified by
 * their MUID (`m_uniqid`).
 *
 * @phpstan-type Message array{m_id: int, m_uniqid: string, m_t_id: int, m_from_name: string, m_from_address: string,
 *     m_subject: string, m_priority: int, m_html: string, m_text: string, m_datesent: ?string, m_queued: int,
 *     m_sent: int, m_max_send: int, m_a_id: int}
 * @phpstan-type MessageInput array{m_t_id: int, m_from_name: string, m_from_address: string, m_subject: string,
 *     m_priority: int, m_max_send: int, m_html: string, m_text: string}
 */
final class MessageRepository
{
    private const COLUMNS = 'm_id, m_uniqid, m_t_id, m_from_name, m_from_address, m_subject, m_priority, m_html, m_text,
        m_datesent, m_queued, m_sent, m_max_send, m_a_id';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return Message|null */
    public function findByMuid(string $muid): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM messages WHERE m_uniqid = ?', [trim($muid)]);
        return $row === false ? null : self::hydrate($row);
    }

    public function count(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM messages');
    }

    /**
     * The administrator's message list, newest first, with delivery and
     * reaction statistics and the selected lists' names.
     *
     * @return list<array<string, mixed>>
     */
    public function adminPage(int $offset, int $limit): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT m.m_id, m.m_uniqid, m.m_subject, m.m_datesent, m.m_queued, m.m_sent, m.m_last_read, m.m_last_like,
                    m.m_reads, m.m_likes, m.m_dislikes,
                    COALESCE((SELECT string_agg(l.l_name, ', ' ORDER BY l.l_system DESC, l.l_name)
                              FROM message_lists ml JOIN lists l ON l.l_id = ml.ml_l_id WHERE ml.ml_m_id = m.m_id), '') AS list_names
             FROM messages m
             ORDER BY m.m_id DESC
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
    }

    /** @return list<array{m_uniqid: string, m_subject: string}> newest first, for pickers */
    public function choices(): array
    {
        return array_map(
            static fn(array $row): array => ['m_uniqid' => (string) $row['m_uniqid'], 'm_subject' => (string) $row['m_subject']],
            $this->db->fetchAllAssociative('SELECT m_uniqid, m_subject FROM messages ORDER BY m_id DESC')
        );
    }

    /** @return Message|null the message an archive was made from */
    public function findByArchiveId(int $archiveId): ?array
    {
        if ($archiveId < 1) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM messages WHERE m_a_id = ? ORDER BY m_id LIMIT 1', [$archiveId]);
        return $row === false ? null : self::hydrate($row);
    }

    /** Count an open (read) and, optionally, a like or dislike. */
    public function recordReaction(int $messageId, ?string $reaction, string $now): void
    {
        $extra = match ($reaction) {
            'like' => ', m_likes = m_likes + 1, m_last_like = :now',
            'dislike' => ', m_dislikes = m_dislikes + 1, m_last_dislike = :now',
            null => '',
            default => throw new \InvalidArgumentException('Unknown reaction ' . $reaction),
        };
        $this->db->executeStatement(
            'UPDATE messages SET m_reads = m_reads + 1, m_last_read = :now' . $extra . ' WHERE m_id = :id',
            ['now' => $now, 'id' => $messageId]
        );
    }

    public function recordForward(int $messageId, int $senderId, int $recipientId, string $recipientEmail, string $now): void
    {
        $this->db->insert('message_forwards', [
            'mf_m_id' => $messageId,
            'mf_sender_s_id' => $senderId,
            'mf_recipient_s_id' => $recipientId,
            'mf_recipient_email' => $recipientEmail,
            'mf_forwarded_at' => $now,
        ]);
    }

    public function findMuidById(int $id): ?string
    {
        $muid = $this->db->fetchOne('SELECT m_uniqid FROM messages WHERE m_id = ?', [$id]);
        return $muid === false ? null : (string) $muid;
    }

    /** @param MessageInput $input */
    public function create(array $input): string
    {
        do {
            $muid = bin2hex(random_bytes(16));
        } while ($this->findByMuid($muid) !== null);
        $this->db->insert('messages', ['m_uniqid' => $muid] + $input);
        return $muid;
    }

    /** @param MessageInput $input */
    public function update(int $messageId, array $input): void
    {
        $this->db->update('messages', $input, ['m_id' => $messageId]);
    }

    /** @return list<array{l_id: int, l_shortcode: string, l_name: string, l_active: bool, l_system: bool}> system lists first */
    public function lists(int $messageId): array
    {
        return array_map(static fn(array $row): array => [
            'l_id' => (int) $row['l_id'],
            'l_shortcode' => (string) $row['l_shortcode'],
            'l_name' => (string) $row['l_name'],
            'l_active' => (bool) $row['l_active'],
            'l_system' => (bool) $row['l_system'],
        ], $this->db->fetchAllAssociative(
            'SELECT l.l_id, l.l_shortcode, l.l_name, l.l_active, l.l_system
             FROM message_lists ml JOIN lists l ON l.l_id = ml.ml_l_id
             WHERE ml.ml_m_id = ?
             ORDER BY l.l_system DESC, l.l_name ASC',
            [$messageId]
        ));
    }

    /**
     * Replace the message's lists with exactly these (unknown ids ignored).
     * An empty selection is valid: drafts may have no audience, and ALL is
     * never added implicitly.
     *
     * @param list<int> $listIds
     */
    public function replaceLists(int $messageId, array $listIds): void
    {
        $this->db->transactional(function (Connection $db) use ($messageId, $listIds): void {
            $db->executeStatement('DELETE FROM message_lists WHERE ml_m_id = ?', [$messageId]);
            foreach (array_values(array_unique(array_filter($listIds, static fn(int $id): bool => $id > 0))) as $listId) {
                $db->executeStatement(
                    'INSERT INTO message_lists (ml_m_id, ml_l_id) SELECT ?, l_id FROM lists WHERE l_id = ?',
                    [$messageId, $listId]
                );
            }
        });
    }

    public function setDateSentIfEmpty(int $messageId, string $now): void
    {
        $this->db->executeStatement('UPDATE messages SET m_datesent = ? WHERE m_id = ? AND m_datesent IS NULL', [$now, $messageId]);
    }

    public function setArchiveId(int $messageId, int $archiveId): void
    {
        $this->db->executeStatement('UPDATE messages SET m_a_id = ? WHERE m_id = ?', [$archiveId, $messageId]);
    }

    public function incrementQueued(int $messageId): void
    {
        $this->db->executeStatement('UPDATE messages SET m_queued = m_queued + 1 WHERE m_id = ?', [$messageId]);
    }

    /** A catto-mail hard bounce of one of this message's deliveries (v5 m_bounces). */
    public function incrementBounces(string $muid): void
    {
        $this->db->executeStatement('UPDATE messages SET m_bounces = m_bounces + 1 WHERE m_uniqid = ?', [$muid]);
    }

    /** A delivery handed back to the queue (its catto-mail job failed before sending). */
    public function decrementSent(int $messageId, int $count): void
    {
        $this->db->executeStatement('UPDATE messages SET m_sent = GREATEST(0, m_sent - ?) WHERE m_id = ?', [max(0, $count), $messageId]);
    }

    public function incrementSent(int $messageId): void
    {
        $this->db->executeStatement('UPDATE messages SET m_sent = m_sent + 1 WHERE m_id = ?', [$messageId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return Message
     */
    private static function hydrate(array $row): array
    {
        return [
            'm_id' => (int) $row['m_id'],
            'm_uniqid' => (string) $row['m_uniqid'],
            'm_t_id' => (int) $row['m_t_id'],
            'm_from_name' => (string) $row['m_from_name'],
            'm_from_address' => (string) $row['m_from_address'],
            'm_subject' => (string) $row['m_subject'],
            'm_priority' => (int) $row['m_priority'],
            'm_html' => (string) $row['m_html'],
            'm_text' => (string) $row['m_text'],
            'm_datesent' => $row['m_datesent'] === null ? null : (string) $row['m_datesent'],
            'm_queued' => (int) $row['m_queued'],
            'm_sent' => (int) $row['m_sent'],
            'm_max_send' => (int) $row['m_max_send'],
            'm_a_id' => (int) $row['m_a_id'],
        ];
    }
}
