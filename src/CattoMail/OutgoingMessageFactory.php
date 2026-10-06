<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Config\SiteConfig;
use App\Repository\ListRepository;
use App\Repository\MessageRepository;

/**
 * Job-level metadata for a ctnlist message: subscription mail with the list
 * as RFC 2919 List-Id (`<list name> <<shortcode>.<APP_DOMAIN>>`), or
 * transactional mail when there is no list context (a proof, or a resend of
 * a draft without lists). The sender is the message's From, else the
 * site's (its domain must be a sending domain registered in catto-mail).
 *
 * @phpstan-import-type Message from MessageRepository
 */
final class OutgoingMessageFactory
{
    /** @var array<string, string> shortcode => list name */
    private array $listNames = [];

    public function __construct(
        private readonly SiteConfig $site,
        private readonly ListRepository $lists,
    ) {
    }

    /** @param Message $message */
    public function forList(array $message, string $listShortcode): OutgoingMessage
    {
        $list = strtoupper(trim($listShortcode));
        if ($list === '') {
            return $this->transactional($message);
        }
        $name = $this->listNames[$list] ??= ($this->lists->findByShortcode($list)['l_name'] ?? $list);
        $label = strtolower($list) . '.' . strtolower(trim($this->site->domain) !== '' ? trim($this->site->domain) : 'localhost');
        return new OutgoingMessage(
            $message['m_uniqid'],
            $list,
            self::phrase($name) . ' <' . $label . '>',
            OutgoingMessage::SUBSCRIPTION,
            $this->senderEmail($message),
            $this->senderName($message),
        );
    }

    /**
     * Transactional mail (no List-Id, no unsubscribe link). $listShortcode is
     * only the context recorded in the Send Log (e.g. a proof's first list).
     *
     * @param Message $message
     */
    public function transactional(array $message, string $listShortcode = ''): OutgoingMessage
    {
        return new OutgoingMessage($message['m_uniqid'], strtoupper(trim($listShortcode)), '', OutgoingMessage::TRANSACTIONAL, $this->senderEmail($message), $this->senderName($message));
    }

    /** @param Message $message */
    private function senderEmail(array $message): string
    {
        return trim((string) $message['m_from_address']) !== '' ? trim((string) $message['m_from_address']) : $this->site->fromAddress;
    }

    /** @param Message $message */
    private function senderName(array $message): string
    {
        return trim((string) $message['m_from_name']) !== '' ? trim((string) $message['m_from_name']) : $this->site->fromName;
    }

    /** A List-Id phrase: plain words, or a quoted string. */
    private static function phrase(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '');
        return preg_match('/^[A-Za-z0-9 !#$%&\'*+\/=?^_`{|}~-]+$/', $name) === 1 ? $name : '"' . addcslashes($name, '"\\') . '"';
    }
}
