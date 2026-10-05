<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Log\MessageActivity;
use App\Log\MessageLog;
use App\Repository\MessageRepository;
use App\Subscriber\Engagement;
use Psr\Clock\ClockInterface;

/**
 * Recipient reactions to a delivered message: opens (tracking pixel), likes
 * and dislikes. Only count for a subscriber the message was sent to; they
 * update the message counters, smlog and the v5 engagement priority (a like
 * is worth 1000, an open 1; a dislike resets the priority).
 */
final class ReactionService
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly MessageLog $messageLog,
        private readonly Engagement $engagement,
        private readonly ClockInterface $clock,
    ) {
    }

    public function open(string $subscriberUuid, string $muid): bool
    {
        return $this->react($subscriberUuid, $muid, null, MessageActivity::Read, fn() => $this->engagement->bump($subscriberUuid));
    }

    public function like(string $subscriberUuid, string $muid): bool
    {
        return $this->react($subscriberUuid, $muid, 'like', MessageActivity::Like, fn() => $this->engagement->bump($subscriberUuid, 1000));
    }

    public function dislike(string $subscriberUuid, string $muid): bool
    {
        return $this->react($subscriberUuid, $muid, 'dislike', MessageActivity::Dislike, fn() => $this->engagement->reset($subscriberUuid));
    }

    /** @param callable(): mixed $engagement */
    private function react(string $subscriberUuid, string $muid, ?string $reaction, MessageActivity $activity, callable $engagement): bool
    {
        $message = $this->messages->findByMuid($muid);
        if ($message === null || !$this->messageLog->wasSent($subscriberUuid, $muid)) {
            return false;
        }
        $this->messages->recordReaction($message['m_id'], $reaction, $this->clock->now()->format('Y-m-d H:i:s'));
        $engagement();
        $this->messageLog->record($subscriberUuid, $muid, $activity);
        return true;
    }
}
