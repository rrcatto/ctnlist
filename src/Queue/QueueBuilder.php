<?php

declare(strict_types=1);

namespace App\Queue;

use App\Campaign\MessageService;
use App\Log\MessageLog;
use App\Repository\MembershipRepository;
use App\Repository\MessageRepository;
use App\Repository\OptionRepository;
use App\Repository\QueueRepository;
use App\Repository\SubscriberRepository;
use App\Suppression\SuppressionChecker;
use Psr\Clock\ClockInterface;

/**
 * Builds the delivery queue from a message's selected lists (union,
 * deduplicated, engaged subscribers first). Subscribers already queued or
 * logged in smlog for the message are never queued again (once-only), and
 * globally suppressed subscribers lose all list memberships instead.
 *
 * @phpstan-import-type Message from MessageRepository
 * @phpstan-import-type Candidate from QueueRepository
 */
final class QueueBuilder
{
    private const BATCH = 5000;
    public const SUPPRESSION_REASON = 'Shared global suppression database';

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly QueueRepository $queue,
        private readonly SubscriberRepository $subscribers,
        private readonly MembershipRepository $memberships,
        private readonly SuppressionChecker $suppression,
        private readonly MessageLog $messageLog,
        private readonly MessageService $messageService,
        private readonly OptionRepository $options,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Queue up to $limit subscribers for one message. */
    public function queueMessage(string $muid, int $limit = 500000): QueueOutcome
    {
        $this->options->set('ActiveMessage', $muid);
        $message = $this->messages->findByMuid($muid);
        if ($message === null) {
            return QueueOutcome::refused('Message not found.');
        }
        if ($this->messages->lists($message['m_id']) === []) {
            return QueueOutcome::refused('The message was not queued because it currently has no target lists. The saved message has not been altered.');
        }
        $message = $this->messageService->prepareForQueue($message);
        $limit = max(0, $limit);

        $queued = 0;
        while ($queued < $limit) {
            $candidates = $this->queue->eligibleForMessage($message['m_id'], $muid, min(self::BATCH, $limit - $queued));
            if ($candidates === []) {
                break;
            }
            $progress = false;
            foreach ($candidates as $candidate) {
                if ($this->suppressed($candidate)) {
                    // Now ineligible, so the next batch cannot return it again.
                    $progress = true;
                    continue;
                }
                if ($this->enqueue($message, $candidate, (string) ($candidate['l_shortcode'] ?? ''))) {
                    $progress = true;
                    if (++$queued >= $limit) {
                        break;
                    }
                }
            }
            if (!$progress) {
                // Every candidate was refused by the database (e.g. a concurrent
                // run queued them first); stop instead of fetching them again.
                break;
            }
        }
        return QueueOutcome::queued($queued);
    }

    /**
     * v5 advanced queue: rotate the selected messages so each subscriber
     * receives at most one of them; $volume is the total across all.
     * Messages without lists are skipped (and left unaltered).
     *
     * @param list<string> $muids
     */
    public function queueRotation(array $muids, int $volume): QueueOutcome
    {
        $muids = array_values(array_unique(array_filter(array_map('trim', $muids), static fn(string $m): bool => $m !== '')));
        if ($muids === []) {
            return QueueOutcome::refused('Select at least one message before queueing.');
        }
        $volume = max(0, $volume);
        if ($volume === 0) {
            return QueueOutcome::queued(0);
        }

        /** @var list<Message> $messages */
        $messages = [];
        foreach ($muids as $muid) {
            $message = $this->messages->findByMuid($muid);
            if ($message === null) {
                return QueueOutcome::refused('Message ' . $muid . ' does not exist.');
            }
            if ($this->messages->lists($message['m_id']) !== []) {
                $messages[] = $this->messageService->prepareForQueue($message);
            }
        }
        if ($messages === []) {
            return QueueOutcome::refused('None of the selected messages currently has a target list.');
        }

        $queued = 0;
        $next = 0;
        $count = count($messages);
        foreach ($this->queue->eligibleForAnyMessage(array_column($messages, 'm_id'), $volume) as $candidate) {
            if ($this->suppressed($candidate)) {
                continue;
            }
            for ($attempt = 0; $attempt < $count; $attempt++) {
                $index = ($next + $attempt) % $count;
                $message = $messages[$index];
                if ($this->messageLog->hasRecord($candidate['s_uuid'], $message['m_uniqid'])
                    || $this->queue->isQueued($message['m_uniqid'], $candidate['s_uuid'])) {
                    continue;
                }
                $list = $this->memberships->eligibleListForMessage($candidate['s_id'], $message['m_id']);
                if ($list !== '' && $this->enqueue($message, $candidate, $list)) {
                    $queued++;
                    $next = ($index + 1) % $count;
                    break;
                }
            }
            if ($queued >= $volume) {
                break;
            }
        }
        return QueueOutcome::queued($queued);
    }

    /**
     * Insert the queue row and apply the v5 queue-time effects: subscriber
     * state, the message's queued count and the smlog once-only row.
     *
     * @param Message $message
     * @param Candidate $candidate
     */
    private function enqueue(array $message, array $candidate, string $list): bool
    {
        $added = $this->queue->add(
            $message['m_uniqid'],
            $candidate['s_uuid'],
            stripslashes($message['m_subject']),
            $candidate['s_email'],
            $list,
            $candidate['s_last_interacted'],
            $message['m_priority'],
            $candidate['s_priority']
        );
        if (!$added) {
            return false;
        }
        $this->subscribers->markQueued($candidate['s_id']);
        $this->messages->incrementQueued($message['m_id']);
        $this->messageLog->ensure($candidate['s_uuid'], $message['m_uniqid'], $list);
        return true;
    }

    /**
     * Global suppression check; a suppressed subscriber loses every list
     * membership (the multi-list form of v5's subscriber-level unsubscribe).
     *
     * @param Candidate $candidate
     */
    private function suppressed(array $candidate): bool
    {
        if (!$this->suppression->isSuppressed($candidate['s_email'])) {
            return false;
        }
        $this->memberships->unsubscribeAll($candidate['s_id'], self::SUPPRESSION_REASON, $this->clock->now()->format('Y-m-d H:i:s'));
        return true;
    }
}
