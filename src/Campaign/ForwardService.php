<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Log\MessageActivity;
use App\Log\MessageLog;
use App\Mail\TransactionalMailer;
use App\Repository\ListRepository;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Subscriber\EmailNormaliser;
use App\Subscriber\Engagement;
use App\Subscriber\SubscriptionService;
use App\Suppression\SuppressionChecker;
use Psr\Clock\ClockInterface;

/**
 * v5 forwarding: a subscriber (or an administrator) sends the current
 * version of a message to up to 10 addresses. Each copy goes through the
 * campaign mail path with the list context of the forwarder's own copy, the
 * recipient becomes a pending subscriber (no consent), and the forwarder
 * gets a notification listing who received it.
 *
 * @phpstan-import-type Message from MessageRepository
 */
final class ForwardService
{
    public const MAX_RECIPIENTS = 10;

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly SubscriberRepository $subscribers,
        private readonly ListRepository $lists,
        private readonly SubscriptionService $subscriptions,
        private readonly MessageService $messageService,
        private readonly SuppressionChecker $suppression,
        private readonly MessageLog $messageLog,
        private readonly Engagement $engagement,
        private readonly TransactionalMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param Message $message
     * @return array{sent: list<string>, problem: ?string} the addresses that received it
     */
    public function forward(string $senderUuid, array $message, string $input): array
    {
        $sender = $this->subscribers->findIdentityByUuid($senderUuid);
        if ($sender === null) {
            return ['sent' => [], 'problem' => 'Unknown subscriber.'];
        }
        $emails = EmailNormaliser::extract($input);
        if ($emails === []) {
            return ['sent' => [], 'problem' => 'No valid email addresses were supplied.'];
        }
        if (count($emails) > self::MAX_RECIPIENTS) {
            return ['sent' => [], 'problem' => 'A message may be forwarded to no more than 10 unique addresses at a time.'];
        }

        $muid = $message['m_uniqid'];
        // An administrator's forward may have no delivery of their own.
        $sourceList = $this->messageLog->listShortcode($sender['s_uuid'], $muid)
            ?: ($this->messages->lists($message['m_id'])[0]['l_shortcode'] ?? '');
        $allListId = $this->lists->findByShortcode('ALL')['l_id'] ?? 0;
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $sent = [];
        foreach ($emails as $email) {
            if ($this->suppression->isSuppressed($email)) {
                continue;
            }
            $this->subscriptions->subscribe($email, 0, $allListId);
            $recipient = $this->subscribers->findIdentityByEmail($email);
            if ($recipient === null || !$this->messageService->sendTo($muid, $email, 'FORWARD-MESSAGE', $sourceList)->accepted()) {
                continue;
            }
            $this->messages->recordForward($message['m_id'], $sender['s_id'], $recipient['s_id'], $email, $now);
            // v5 counts one forward per recipient.
            $this->messageLog->record($sender['s_uuid'], $muid, MessageActivity::Forward);
            $this->engagement->bump($sender['s_uuid'], 5);
            $sent[] = $email;
        }

        if ($sent !== []) {
            $list = implode(', ', $sent);
            $this->mailer->sendNotification(
                $muid,
                'FORWARD-NOTIFICATION',
                $message['m_from_address'],
                $sender['s_email'],
                trim($sender['s_fname'] . ' ' . $sender['s_lname']),
                $sender['s_email'] . ' forwarded ' . $muid,
                '<p>You forwarded the message <strong>' . htmlspecialchars($muid) . '</strong> to:</p><p>' . htmlspecialchars($list) . '</p>',
                "You forwarded message {$muid} to: {$list}\n",
                $sender['s_uuid'],
                $sourceList,
            );
        }
        return ['sent' => $sent, 'problem' => null];
    }
}
