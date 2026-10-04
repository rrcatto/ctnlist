<?php

declare(strict_types=1);

namespace App\Campaign;

use App\Log\MessageLog;
use App\Mail\CampaignDelivery;
use App\Mail\CampaignMailer;
use App\Mail\MailConnectionFactory;
use App\Mail\SmtpServerPool;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;

/**
 * Campaign message lifecycle: saving drafts exactly as the administrator
 * supplied them, preparing a message for its first queueing, and direct
 * sends of campaign content (proof, resend).
 *
 * @phpstan-import-type Message from MessageRepository
 * @phpstan-import-type MessageInput from MessageRepository
 */
final class MessageService
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly SubscriberRepository $subscribers,
        private readonly ArchiveService $archives,
        private readonly TemplateRenderer $renderer,
        private readonly CampaignMailer $mailer,
        private readonly MailConnectionFactory $connections,
        private readonly SmtpServerPool $servers,
        private readonly MessageLog $messageLog,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Create ($muid null) or update a message. Values are stored as supplied
     * (no defaults, clamping or completeness checks) and the lists are exactly
     * the selection, which may be empty.
     *
     * @param MessageInput $input
     * @param list<int> $listIds
     * @return string the message's MUID
     * @throws MessageNotFound
     */
    public function save(?string $muid, array $input, array $listIds): string
    {
        if ($muid === null || trim($muid) === '') {
            $muid = $this->messages->create($input);
            $message = $this->messages->findByMuid($muid) ?? throw new MessageNotFound($muid);
        } else {
            $message = $this->messages->findByMuid($muid) ?? throw new MessageNotFound($muid);
            $this->messages->update($message['m_id'], $input);
        }
        $this->messages->replaceLists($message['m_id'], $listIds);
        return $message['m_uniqid'];
    }

    /**
     * First-queue preparation: record the send date and create the archive
     * if the message has none yet.
     *
     * @param Message $message
     * @return Message as stored afterwards
     */
    public function prepareForQueue(array $message): array
    {
        $this->messages->setDateSentIfEmpty($message['m_id'], $this->clock->now()->format('Y-m-d H:i:s'));
        if ($message['m_a_id'] === 0) {
            $archiveId = $this->archives->archive($message);
            if ($archiveId > 0) {
                $this->messages->setArchiveId($message['m_id'], $archiveId);
            }
        }
        return $this->messages->findByMuid($message['m_uniqid']) ?? $message;
    }

    /**
     * Send campaign content directly to the subscriber with this address,
     * through the campaign mail path (Send Log and smlog are updated). The
     * list context is the one given, else the one recorded when the message
     * was sent to them, else the message's first list, else none.
     *
     * @param string $type PROOF, RESEND or FORWARD-MESSAGE
     */
    public function sendTo(string $muid, string $email, string $type = 'RESEND', string $listShortcode = ''): bool
    {
        $message = $this->messages->findByMuid($muid);
        $recipient = trim($email) === '' ? null : $this->subscribers->findRecipientByEmail($email);
        $server = $this->servers->transactionalServer();
        if ($message === null || $recipient === null || $server === null) {
            return false;
        }

        $list = strtoupper(trim($listShortcode));
        if ($list === '') {
            $list = $this->messageLog->listShortcode($recipient['s_uuid'], $muid);
        }
        if ($list === '') {
            $list = $this->messages->lists($message['m_id'])[0]['l_shortcode'] ?? '';
        }

        $rendered = $this->renderer->render($message, $recipient, $list);
        $connection = $this->connections->create();
        if (!$connection->open($server)) {
            return false;
        }
        try {
            return $this->mailer->send($connection, new CampaignDelivery(
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
            ), $type);
        } finally {
            $connection->close();
        }
    }
}
