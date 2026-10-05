<?php

declare(strict_types=1);

namespace App\Queue;

use App\Campaign\TemplateRenderer;
use App\Mail\CampaignDelivery;
use App\Mail\CampaignMailer;
use App\Mail\MailConnection;
use App\Mail\MailConnectionFactory;
use App\Mail\SmtpServer;
use App\Mail\SmtpServerPool;
use App\Repository\MembershipRepository;
use App\Repository\MessageRepository;
use App\Repository\OptionRepository;
use App\Repository\QueueRepository;
use App\Repository\SubscriberRepository;
use App\Suppression\SuppressionChecker;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sends queued campaign mail with the v5 contract: batches per server, a
 * pause between batches, one reconnect/failover retry of a failed item
 * before stopping, the SendQueue stop flag, m_max_send as a hard maximum
 * (zero included), and suppression plus list eligibility re-checked
 * immediately before each send.
 */
final class QueueProcessor
{
    public const CURRENTLY_SENDING = 'CurrentlySending';
    public const SEND_QUEUE = 'SendQueue';

    public function __construct(
        private readonly QueueRepository $queue,
        private readonly MessageRepository $messages,
        private readonly SubscriberRepository $subscribers,
        private readonly MembershipRepository $memberships,
        private readonly SuppressionChecker $suppression,
        private readonly TemplateRenderer $renderer,
        private readonly CampaignMailer $mailer,
        private readonly SmtpServerPool $servers,
        private readonly MailConnectionFactory $connections,
        private readonly OptionRepository $options,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int messages sent */
    public function process(string $muid = '', int $limit = 250000): int
    {
        if ($this->options->get(self::CURRENTLY_SENDING) === 'Y') {
            return 0;
        }
        $this->options->set(self::SEND_QUEUE, 'Y');
        $this->options->set(self::CURRENTLY_SENDING, 'Y');

        $connection = $this->connections->create();
        try {
            $servers = $this->servers->campaignServers();
            if ($servers === [] || !$this->openFirstAvailable($connection, $servers)) {
                return 0;
            }
            return $this->sendBatches($connection, $servers, $muid, $limit);
        } finally {
            $connection->close();
            $this->options->set(self::CURRENTLY_SENDING, 'N');
        }
    }

    /** Ask a running process to stop before its next message. */
    public function requestStop(): void
    {
        $this->options->set(self::SEND_QUEUE, 'N');
    }

    /** @param list<SmtpServer> $servers */
    private function sendBatches(MailConnection $connection, array $servers, string $muid, int $limit): int
    {
        $sent = 0;
        do {
            $batch = $this->queue->nextBatch($muid, $this->server($connection)->batchSize);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                if ($sent >= $limit) {
                    break;
                }
                if ($this->options->get(self::SEND_QUEUE) === 'N') {
                    return $sent;
                }
                $recipient = $this->subscribers->findRecipientByUuid($row['q_s_uuid']);
                $message = $this->messages->findByMuid($row['q_muid']);
                if ($recipient === null || $message === null) {
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
                    return $sent;
                }

                $rendered = $this->renderer->render($message, $recipient, $list);
                $delivery = new CampaignDelivery(
                    $message['m_uniqid'],
                    $message['m_subject'],
                    $message['m_from_name'],
                    $message['m_from_address'],
                    $recipient['s_uuid'],
                    $recipient['s_email'],
                    trim($recipient['s_fname'] . ' ' . $recipient['s_lname']),
                    $list,
                    $rendered->html,
                    $rendered->text,
                );
                $delivered = $this->mailer->send($connection, $delivery);
                if (!$delivered) {
                    // v5: reconnect (failing over from the first server) and retry once.
                    $delay = $this->server($connection)->delay;
                    $connection->close();
                    $this->clock->sleep($delay);
                    if (!$this->openFirstAvailable($connection, $servers)) {
                        return $sent;
                    }
                    $delivered = $this->mailer->send($connection, $delivery);
                }
                if (!$delivered) {
                    return $sent;
                }
                $this->messages->incrementSent($message['m_id']);
                $this->queue->delete($row['q_id']);
                $sent++;
            }
            $this->clock->sleep($this->server($connection)->delay);
        } while ($sent < $limit);
        return $sent;
    }

    /** @param list<SmtpServer> $servers */
    private function openFirstAvailable(MailConnection $connection, array $servers): bool
    {
        foreach ($servers as $server) {
            if ($connection->open($server)) {
                return true;
            }
        }
        return false;
    }

    private function server(MailConnection $connection): SmtpServer
    {
        return $connection->server() ?? throw new \LogicException('No open mail connection.');
    }
}
