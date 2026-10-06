<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\CattoMailSendRepository;
use App\Repository\MembershipRepository;
use App\Repository\MessageRepository;
use App\Repository\QueueRepository;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;

/**
 * Maps catto-mail message outcomes back to ctnlist (by
 * external_recipient_reference, the cattomail_recipients uuid), from webhooks
 * and from polling alike. catto-mail itself suppresses hard-bounced and
 * complaining addresses globally; ctnlist only keeps the business state it
 * needs:
 *
 * - every status: the recipient row's status (never regressing a final one);
 * - hard_bounced (once per delivery): subscriber s_delivery_state =
 *   hard_bounced, s_bounces + 1, the message's m_bounces + 1, and their
 *   queued deliveries removed. Consent and list memberships are unchanged;
 * - complained (once per delivery): subscriber s_delivery_state = complained,
 *   unsubscribed from every ctnlist list (reason COMPLAINT_REASON), queued
 *   deliveries removed.
 *
 * Subscribers in either state are left out of campaign selection until an
 * administrator clears it. Nothing is written to the ctnlist suppression
 * database or reported back to catto-mail.
 *
 * @phpstan-import-type Recipient from CattoMailSendRepository
 */
final class DeliveryProblems
{
    public const COMPLAINT_REASON = 'Spam complaint reported through catto-mail';

    /** catto-mail message statuses (status vocabulary); anything else is ignored, not stored. */
    private const STATUSES = ['created', 'queued', 'submitted', 'deferred', 'outcome_unknown', 'remote_accepted', 'soft_bounced',
        'hard_bounced', 'complained', 'failed', 'suppressed'];

    public function __construct(
        private readonly CattoMailSendRepository $outbox,
        private readonly SubscriberRepository $subscribers,
        private readonly MembershipRepository $memberships,
        private readonly MessageRepository $messages,
        private readonly QueueRepository $queue,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param Recipient $recipient
     * @return string what was done (for the webhook log)
     */
    public function apply(array $recipient, string $status, ?string $remoteMessageId, ?string $resolvedAt): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            return 'ignored unknown message status ' . mb_substr($status, 0, 40);
        }
        $this->outbox->updateRecipientStatus($recipient['crp_id'], $status, $remoteMessageId, self::timestamp($resolvedAt));
        if (!in_array($status, ['hard_bounced', 'complained'], true) || $recipient['crp_s_uuid'] === null) {
            return 'status ' . $status;
        }
        $subscriber = $this->subscribers->findIdentityByUuid($recipient['crp_s_uuid']);
        if ($subscriber === null) {
            return 'status ' . $status . ' (subscriber no longer exists)';
        }
        // The claim and its effects commit together: a crash in between leaves the claim
        // unmade, so a repeated event (or the worker's polling) applies it later, still once.
        return $this->outbox->transactional(function () use ($recipient, $status, $subscriber): string {
            if (!$this->outbox->claimEffect($recipient['crp_id'], $status)) {
                return 'status ' . $status . ' (already applied)';
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $this->subscribers->recordDeliveryProblem($subscriber['s_id'], $status, $now);
            if ($status === 'hard_bounced') {
                $this->messages->incrementBounces($recipient['crp_muid']);
            } else {
                $this->memberships->unsubscribeAll($subscriber['s_id'], self::COMPLAINT_REASON, $now);
            }
            $this->queue->deleteForSubscriber($subscriber['s_uuid']);
            return $status . ' applied to subscriber ' . $subscriber['s_uuid'];
        });
    }

    /** catto-mail timestamps are RFC 3339 UTC; store them in PHP's timezone like every other ctnlist timestamp. */
    public static function timestamp(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
