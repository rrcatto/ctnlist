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
