<?php

declare(strict_types=1);

namespace App\Campaign;

use App\CattoMail\CattoMailSender;
use App\CattoMail\OutgoingMessageFactory;
use App\CattoMail\OutgoingRecipient;
use App\CattoMail\SendOutcome;
use App\Log\MessageLog;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Validator\InvalidField;
use Psr\Clock\ClockInterface;

/**
 * Campaign message lifecycle: saving drafts exactly as the administrator
 * supplied them, preparing a message for its first queueing, and direct
 * sends of campaign content (proofs to any address; resends and forwards to
 * subscribers), which go through catto-mail like queue sends.
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
        private readonly CattoMailSender $sender,
        private readonly OutgoingMessageFactory $outgoing,
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
     * A proof copy to any valid address chosen by the administrator, sent as
     * a one-recipient transactional catto-mail job. The address needs no
     * subscriber record and none is created or looked up: the message is
     * rendered for TemplateRenderer::PROOF_RECIPIENT and logged only in the
     * Send Log (type PROOF, no subscriber); queue, smlog, consent and list
     * memberships are untouched.
     *
     * @throws InvalidField for an invalid address
     * @throws MessageNotFound
     */
    public function sendProof(string $muid, string $email): SendOutcome
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidField('email', 'Enter a valid email address for the proof.');
        }
        $message = $this->messages->findByMuid($muid) ?? throw new MessageNotFound($muid);
        $list = $this->messages->lists($message['m_id'])[0]['l_shortcode'] ?? '';
        $rendered = $this->renderer->renderProof($message, $list);
        return $this->sender->sendOne('proof', $this->outgoing->transactional($message, $list),
            new OutgoingRecipient($email, null, 'PROOF', (string) $message['m_subject'], $rendered->html, $rendered->text));
    }

    /**
     * Send campaign content directly to the subscriber with this address
     * (resend, forward copy) through catto-mail; on handoff the Send Log and
     * smlog are updated. The list context is the one given, else the one
     * recorded when the message was sent to them, else the message's first
     * list, else none (then it goes as transactional mail).
     *
     * @param string $type RESEND or FORWARD-MESSAGE
     */
    public function sendTo(string $muid, string $email, string $type = 'RESEND', string $listShortcode = ''): SendOutcome
    {
        $message = $this->messages->findByMuid($muid);
        $recipient = trim($email) === '' ? null : $this->subscribers->findRecipientByEmail($email);
        if ($message === null || $recipient === null) {
            return SendOutcome::of(0, 0, 'Unknown message or subscriber.', false);
        }

        $list = strtoupper(trim($listShortcode));
        if ($list === '') {
            $list = $this->messageLog->listShortcode($recipient['s_uuid'], $muid);
        }
        if ($list === '') {
            $list = $this->messages->lists($message['m_id'])[0]['l_shortcode'] ?? '';
        }

        $rendered = $this->renderer->render($message, $recipient, $list);
        return $this->sender->sendOne($type === 'RESEND' ? 'resend' : 'forward', $this->outgoing->forList($message, $list),
            new OutgoingRecipient($recipient['s_email'], $recipient['s_uuid'], $type, (string) $message['m_subject'], $rendered->html, $rendered->text));
    }
}
