<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\CattoMailSendRepository;
use App\Repository\MessageRepository;
use App\Repository\QueueRepository;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;

/**
 * Returns the subscribers of a send job that catto-mail refused before
 * anything was sent (status failed, recipients not_sent) to the queue, so the
 * campaign can be sent again once the cause is fixed (for example an
 * unregistered sender domain). Nothing that catto-mail accepted is requeued.
 */
final class SendJobAdmin
{
    public function __construct(
        private readonly CattoMailSendRepository $outbox,
        private readonly QueueRepository $queue,
        private readonly MessageRepository $messages,
        private readonly SubscriberRepository $subscribers,
        private readonly CattoMailSender $sender,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * An administrator's "retry now" for a job not yet sealed at catto-mail:
     * the same as the worker's retry (stored remote id and Idempotency-Keys,
     * so nothing is created or sent twice). Not while its run is still
     * staging it.
     *
     * @return int recipients handed off
     * @throws \InvalidArgumentException when the job cannot be retried now (with the reason)
     * @throws CattoMailException when catto-mail refuses or cannot be reached (the job stays for the worker)
     */
    public function retrySending(int $jobId): int
    {
        $job = $this->outbox->job($jobId);
        if ($job === null || !in_array($job['csj_status'], ['open', 'ready'], true)) {
            throw new \InvalidArgumentException('Only a send job not yet sealed at catto-mail can be retried.');
        }
        // Without a configuration nothing can be sent; the job must not be marked as refused for that.
        $problem = $this->sender->problem();
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        if (!$this->outbox->runIsOver($job['csj_cr_id'], $this->clock->now()->modify('-3600 seconds')->format('Y-m-d H:i:s'))) {
            throw new \InvalidArgumentException('Its run is still staging recipients; it is finished automatically.');
        }
        $this->outbox->closeJob($jobId);
        return $this->sender->flushJob($jobId);
    }

    /** @return int subscribers returned to the queue */
    public function requeueFailed(int $jobId): int
    {
        $job = $this->outbox->job($jobId);
        if ($job === null || $job['csj_status'] !== 'failed' || $this->outbox->runKind($job['csj_cr_id']) !== 'campaign') {
            throw new \InvalidArgumentException('Only a failed campaign send job can be returned to the queue.');
        }
        $message = $this->messages->findByMuid($job['csj_muid']);
        if ($message === null) {
            throw new \InvalidArgumentException('The message no longer exists.');
        }
        return $this->outbox->transactional(function () use ($job, $message): int {
            $requeued = 0;
            $recipients = $this->outbox->notSentRecipients($job['csj_id']);
            foreach ($recipients as $recipient) {
                $subscriber = $this->subscribers->findRecipientByUuid($recipient['crp_s_uuid']);
                if ($subscriber !== null && $this->queue->add($message['m_uniqid'], $subscriber['s_uuid'], (string) $message['m_subject'], $subscriber['s_email'],
                    $recipient['crp_list_shortcode'], $subscriber['s_last_interacted'], $message['m_priority'], $subscriber['s_priority'])) {
                    $requeued++;
                }
            }
            $this->outbox->deleteRecipients($job['csj_id'], 'not_sent');
            // They were counted as sent when staged; they are queued again instead.
            $this->messages->decrementSent($message['m_id'], count($recipients));
            return $requeued;
        });
    }
}
