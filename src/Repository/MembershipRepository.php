<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * List memberships (`list_subscribers`). A subscriber is eligible for a list
 * only while ls_confirmed = TRUE AND ls_unsubscribed = FALSE (and the list is
 * active). Consent changes are audited by a trigger.
 */
final class MembershipRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Every active list with this subscriber's membership, if any (system
     * lists first): the profile's membership report.
     *
     * @return list<array{l_id: int, l_shortcode: string, l_name: string, ls_uuid: ?string, ls_subscribed_at: ?string, confirmed: bool, unsubscribed: bool}>
     */
    public function forSubscriber(int $subscriberId): array
    {
        return array_map(static fn(array $row): array => [
            'l_id' => (int) $row['l_id'],
            'l_shortcode' => (string) $row['l_shortcode'],
            'l_name' => (string) $row['l_name'],
            'ls_uuid' => $row['ls_uuid'] === null ? null : (string) $row['ls_uuid'],
            'ls_subscribed_at' => $row['ls_subscribed_at'] === null ? null : (string) $row['ls_subscribed_at'],
            'confirmed' => (bool) $row['ls_confirmed'],
            'unsubscribed' => (bool) $row['ls_unsubscribed'],
        ], $this->db->fetchAllAssociative(
            'SELECT l.l_id, l.l_shortcode, l.l_name, ls.ls_uuid, ls.ls_subscribed_at, ls.ls_confirmed, ls.ls_unsubscribed
             FROM lists l
             LEFT JOIN list_subscribers ls ON ls.ls_l_id = l.l_id AND ls.ls_s_id = ?
             WHERE l.l_active = TRUE
             ORDER BY l.l_system DESC, l.l_name ASC',
            [$subscriberId]
        ));
    }

    /**
     * Grant consent for one list. The membership is created first (if
     * needed) and then updated, so the audit trigger records "joined" and
     * "confirmed" as separate events, as in v5.
     */
    public function confirm(int $subscriberId, int $listId, string $now): void
    {
        $this->ensure($subscriberId, $listId);
        $this->db->executeStatement(
            'UPDATE list_subscribers
             SET ls_confirmed = TRUE, ls_unsubscribed = FALSE, ls_confirmed_at = ?,
                 ls_unsubscribed_at = NULL, ls_unsubscribe_reason = NULL
             WHERE ls_s_id = ? AND ls_l_id = ?',
            [$now, $subscriberId, $listId]
        );
    }

    /** Withdraw consent for one list. */
    public function unsubscribe(int $subscriberId, int $listId, string $reason, string $now): void
    {
        $this->ensure($subscriberId, $listId);
        $this->db->executeStatement(
            'UPDATE list_subscribers
             SET ls_confirmed = FALSE, ls_unsubscribed = TRUE, ls_unsubscribed_at = ?, ls_unsubscribe_reason = ?
             WHERE ls_s_id = ? AND ls_l_id = ?',
            [$now, mb_substr(trim($reason), 0, 255), $subscriberId, $listId]
        );
    }

    /** Create the membership (no consent) if it does not exist; true when created. */
    public function ensure(int $subscriberId, int $listId): bool
    {
        return $this->db->executeStatement(
            'INSERT INTO list_subscribers (ls_s_id, ls_l_id) VALUES (?, ?) ON CONFLICT (ls_s_id, ls_l_id) DO NOTHING',
            [$subscriberId, $listId]
        ) === 1;
    }

    /** Make every membership ineligible (a global unsubscribe, the multi-list form of v5's s_unsubscribe). */
    public function unsubscribeAll(int $subscriberId, string $reason, string $now): void
    {
        $this->db->executeStatement(
            'UPDATE list_subscribers
             SET ls_confirmed = FALSE, ls_unsubscribed = TRUE, ls_unsubscribed_at = ?, ls_unsubscribe_reason = ?
             WHERE ls_s_id = ?',
            [$now, mb_substr(trim($reason), 0, 255), $subscriberId]
        );
    }

    /**
     * The shortcode of a list of this message for which the subscriber is
     * eligible ('' if none): the preferred one if still valid, else system
     * lists first, then by shortcode.
     */
    public function eligibleListForMessage(int $subscriberId, int $messageId, string $preferred = ''): string
    {
        $preferred = strtoupper(trim($preferred));
        $shortcode = $this->db->fetchOne(
            'SELECT l.l_shortcode
             FROM message_lists ml
             JOIN lists l ON l.l_id = ml.ml_l_id
             JOIN list_subscribers ls ON ls.ls_l_id = ml.ml_l_id
             WHERE ml.ml_m_id = :mid AND ls.ls_s_id = :sid
               AND ls.ls_confirmed = TRUE AND ls.ls_unsubscribed = FALSE AND l.l_active = TRUE
             ORDER BY CASE WHEN l.l_shortcode = :preferred THEN 0 ELSE 1 END, l.l_system DESC, l.l_shortcode ASC
             LIMIT 1',
            ['mid' => $messageId, 'sid' => $subscriberId, 'preferred' => $preferred]
        );
        return $shortcode === false ? '' : strtoupper((string) $shortcode);
    }
}
