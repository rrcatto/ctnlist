<?php

declare(strict_types=1);

namespace App\Queue;

use App\Campaign\TemplateRenderer;
use App\CattoMail\CattoMailException;
use App\CattoMail\CattoMailSender;
use App\CattoMail\CattoMailUnavailable;
use App\CattoMail\OutgoingMessageFactory;
use App\CattoMail\OutgoingRecipient;
use App\CattoMail\SendOutcome;
use App\Maintenance\DatabaseLock;
use App\Repository\MembershipRepository;
use App\Repository\MessageRepository;
use App\Repository\OptionRepository;
use App\Repository\QueueRepository;
use App\Repository\SubscriberRepository;
use App\Suppression\SuppressionChecker;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Sends queued campaign mail through catto-mail, keeping the v5 queue
 * contract: queue order, the SendQueue stop flag, m_max_send as a hard
 * maximum (zero included), and suppression plus list eligibility re-checked
 * immediately before each message is handed over. Each message is rendered
 * here for its recipient (catto-mail performs no mail merge) and staged in
 * the catto-mail outbox in the same transaction that removes its queue row;
 * the run's send jobs are then submitted (CattoMailSender). Recipients whose
 * address hard-bounced or complained are no longer sent to. One run at a
 * time (DatabaseLock::QUEUE).
 */
final class QueueProcessor
{
    public const CURRENTLY_SENDING = 'CurrentlySending';
    public const SEND_QUEUE = 'SendQueue';
    private const FETCH = 500;

    public function __construct(
        private readonly QueueRepository $queue,
        private readonly MessageRepository $messages,
        private readonly SubscriberRepository $subscribers,
        private readonly MembershipRepository $memberships,
        private readonly SuppressionChecker $suppression,
        private readonly TemplateRenderer $renderer,
        private readonly CattoMailSender $sender,
        private readonly OutgoingMessageFactory $outgoing,
        private readonly OptionRepository $options,
        private readonly ClockInterface $clock,
        private readonly DatabaseLock $lock,
    ) {
    }

    public function process(string $muid = '', int $limit = 250000): SendOutcome
    {
        $problem = $this->sender->problem();
        if ($problem !== null) {
            return SendOutcome::of(0, 0, $problem, false);
        }
        // The lock, not the CurrentlySending flag, decides: a run killed by a time limit, a PHP-FPM
        // reload or a reboot never resets the flag, but the database frees its lock.
        if (!$this->lock->acquire(DatabaseLock::QUEUE)) {
            return SendOutcome::of(0, 0, 'The queue is already being sent.', true);
        }
        try {
            $this->options->set(self::SEND_QUEUE, 'Y');
            $this->options->set(self::CURRENTLY_SENDING, 'Y');

            $run = $this->sender->startRun('campaign', $muid);
            $staged = 0;
            $problem = null;
            $retryable = true;
            try {
                $staged = $this->stage($run, $muid, max(0, $limit), $problem);
                // The message itself stopped the run (no content, maximum reached): nothing to retry.
                $retryable = $problem === null;
            } catch (CattoMailException $e) {
                $problem = $e->getMessage();
                $retryable = $e instanceof CattoMailUnavailable;
            } finally {
                try {
                    $finished = $this->sender->finishRun($run, 0);
                } finally {
                    $this->options->set(self::CURRENTLY_SENDING, 'N');
                }
            }
        } finally {
            $this->lock->release(DatabaseLock::QUEUE);
        }
        $problem ??= $finished->problem;
        $retryable = $retryable && ($finished->problem === null || $finished->status !== SendOutcome::FAILED);
        return SendOutcome::of($staged, $finished->handedOff, $problem, $retryable);
    }

    /** Ask a running process to stop before its next message. */
    public function requestStop(): void
    {
        $this->options->set(self::SEND_QUEUE, 'N');
    }

    /** @return int recipients staged */
    private function stage(int $run, string $muid, int $limit, ?string &$problem): int
    {
        $staged = 0;
        while ($staged < $limit) {
            $batch = $this->queue->nextBatch($muid, min(self::FETCH, $limit - $staged));
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                if ($staged >= $limit || $this->options->get(self::SEND_QUEUE) === 'N') {
                    return $staged;
                }
                $recipient = $this->subscribers->findRecipientByUuid($row['q_s_uuid']);
                $message = $this->messages->findByMuid($row['q_muid']);
                if ($recipient === null || $message === null || $recipient['s_delivery_state'] !== 'ok') {
                    $this->queue->delete($row['q_id']);
                    continue;
                }
                // The shared suppression source may have changed since queueing.
                if ($this->suppression->isSuppressed($recipient['s_email'])) {
                    $this->memberships->unsubscribeAll($recipient['s_id'], QueueBuilder::SUPPRESSION_REASON, $this->clock->now()->format('Y-m-d H:i:s'));
                    $this->queue->delete($row['q_id']);
                    continue;
                }
                $list = $this->memberships->eligibleListForMessage($recipient['s_id'], $message['m_id'], $row['q_list_shortcode']);
                if ($list === '') {
                    $this->queue->delete($row['q_id']);
                    continue;
                }
                if ($message['m_sent'] >= $message['m_max_send']) {
                    $problem = 'Message ' . $message['m_uniqid'] . ' has reached its maximum of ' . $message['m_max_send']
                        . ' sends (Maximum sends on the message); the rest stays queued.';
                    return $staged;
                }

                $rendered = $this->renderer->render($message, $recipient, $list);
                if (trim($rendered->html) === '' && trim($rendered->text) === '') {
                    $problem = 'Message ' . $message['m_uniqid'] . ' has no content; it was not sent.';
                    return $staged;
                }
                $queueId = $row['q_id'];
                $messageId = $message['m_id'];
                try {
                    // The queue row leaves only with its durable staging record, and only once:
                    // a run that finds the row already gone (another run staged it) rolls back.
                    $this->sender->stage(
                        $run,
                        $this->outgoing->forList($message, $list),
                        new OutgoingRecipient($recipient['s_email'], $recipient['s_uuid'], 'MESSAGE', (string) $message['m_subject'], $rendered->html, $rendered->text),
                        function () use ($queueId, $messageId): void {
                            if (!$this->queue->delete($queueId)) {
                                throw new QueueRowClaimed('Queue row ' . $queueId . ' was staged by another run.');
                            }
                            $this->messages->incrementSent($messageId);
                        },
                    );
                } catch (QueueRowClaimed | UniqueConstraintViolationException) {
                    // Already staged by another run (or an active delivery of this message to this subscriber exists).
                    $this->queue->delete($queueId);
                    continue;
                }
                $staged++;
            }
        }
        return $staged;
    }
}
