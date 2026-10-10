<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\EmailAddress;
use App\Validator\WholeNumber;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A campaign message in the editor (MessageType). Drafts may be incomplete
 * and have no lists; only what cannot be stored or sent as entered is
 * rejected (lengths, a malformed sender address, out-of-range numbers).
 * Saving never sends.
 */
final class MessageDraft
{
    public ?string $muid = null;

    #[Assert\Length(max: 200)]
    public string $subject = '';

    public string $html = '';

    public string $text = '';

    public int $templateId = 0;

    #[Assert\Length(max: 100)]
    public string $fromName = '';

    #[EmailAddress]
    public string $fromAddress = '';

    #[WholeNumber(min: -2147483648, max: 2147483647)]
    public int $priority = 0;

    /** The hard maximum of queue sends, as in v5: 0 sends none. */
    #[WholeNumber(min: 0, max: 2147483647)]
    public int $maxSend = 0;

    /** @var list<int> list ids, exactly as selected (ALL is never added) */
    public array $listIds = [];

    /**
     * @param array{m_uniqid: string, m_t_id: int, m_from_name: string, m_from_address: string, m_subject: string, m_priority: int, m_max_send: int, m_html: string, m_text: string} $message
     * @param list<int> $listIds
     */
    public static function fromMessage(array $message, array $listIds): self
    {
        $draft = new self();
        $draft->muid = $message['m_uniqid'] === '' ? null : $message['m_uniqid'];
        $draft->subject = $message['m_subject'];
        $draft->html = $message['m_html'];
        $draft->text = $message['m_text'];
        $draft->templateId = $message['m_t_id'];
        $draft->fromName = $message['m_from_name'];
        $draft->fromAddress = $message['m_from_address'];
        $draft->priority = $message['m_priority'];
        $draft->maxSend = $message['m_max_send'];
        $draft->listIds = $listIds;
        return $draft;
    }

    /** @return array{m_t_id: int, m_from_name: string, m_from_address: string, m_subject: string, m_priority: int, m_max_send: int, m_html: string, m_text: string} MessageService::save() input */
    public function input(): array
    {
        return [
            'm_t_id' => $this->templateId,
            'm_from_name' => $this->fromName,
            'm_from_address' => $this->fromAddress,
            'm_subject' => $this->subject,
            'm_priority' => $this->priority,
            'm_max_send' => $this->maxSend,
            'm_html' => $this->html,
            'm_text' => $this->text,
        ];
    }
}
