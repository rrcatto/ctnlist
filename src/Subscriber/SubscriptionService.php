<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Mail\TransactionalMailer;
use App\Repository\ListRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriberRepository;
use App\Suppression\SuppressionChecker;
use Psr\Clock\ClockInterface;

/**
 * v5 SimpleSubscribe: add an address to a list as a pending member. Never
 * grants bulk-mail consent; the subscriber is invited to confirm instead.
 */
final class SubscriptionService
{
    private const MAX_PRIORITY = 10000000;

    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly ListRepository $lists,
        private readonly MembershipRepository $memberships,
        private readonly SuppressionChecker $suppression,
        private readonly TransactionalMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Create the identity if needed, raise its priority to $priority (capped),
     * create a pending membership of the list and send invitations: one for
     * ALL when the identity is new (its insert trigger joined ALL), and one
     * for the list when that membership is new (unless it is ALL for a new
     * identity, already invited). False for unusable or suppressed addresses
     * and unknown lists.
     */
    public function subscribe(string $email, int $priority, int $listId): bool
    {
        $email = EmailNormaliser::correct($email);
        if (!EmailNormaliser::isValid($email) || $this->suppression->isSuppressed($email)) {
            return false;
        }
        $list = $this->lists->findById($listId);
        $found = $list === null ? null : $this->subscribers->findOrCreateIdentity($email, $this->clock->now()->format('Y-m-d H:i:s'));
        if ($list === null || $found === null) {
            return false;
        }
        $identity = $found['identity'];
        $this->subscribers->raisePriority($identity['s_id'], min($priority, self::MAX_PRIORITY));
        $membershipCreated = $this->memberships->ensure($identity['s_id'], $listId);

        if ($found['created'] && ($all = $this->lists->findByShortcode('ALL')) !== null) {
            $this->mailer->sendListConfirmationInvitation($identity['s_email'], $identity['s_uuid'], $all['l_shortcode'], $all['l_name']);
        }
        if ($membershipCreated && !($found['created'] && $list['l_shortcode'] === 'ALL')) {
            $this->mailer->sendListConfirmationInvitation($identity['s_email'], $identity['s_uuid'], $list['l_shortcode'], $list['l_name']);
        }
        return true;
    }
}
