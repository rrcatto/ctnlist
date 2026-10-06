<?php

declare(strict_types=1);

namespace App\CattoMail;

/**
 * Job-level metadata of what is sent: the message, its list context and
 * class, and the sender. Recipients with the same OutgoingMessage share a
 * catto-mail send job (up to 10,000 of them).
 */
final class OutgoingMessage
{
    public const SUBSCRIPTION = 'subscription';
    public const TRANSACTIONAL = 'transactional';

    public function __construct(
        public readonly string $muid,
        /** List shortcode: required for subscription mail; for transactional mail only the logged context. */
        public readonly string $listShortcode,
        /** RFC 2919 List-Id value for subscription mail (e.g. `News <news.example.com>`). */
        public readonly string $listId,
        public readonly string $messageClass,
        public readonly string $senderEmail,
        public readonly string $senderName,
    ) {
    }

    public function key(): string
    {
        return implode('|', [$this->muid, strtoupper($this->listShortcode), $this->messageClass, strtolower($this->senderEmail), $this->senderName]);
    }
}
