<?php

declare(strict_types=1);

namespace App\Campaign;

/**
 * What a message must have. To be saved, a subject (MessageDraft) of at most
 * SUBJECT_MAX characters; a draft may otherwise be blank. To be sent,
 * content: every send path refuses a blank message (queueing, the queue run,
 * proofs, resends and forwards).
 */
final class MessageContent
{
    /** The longest subject: the width of every column that stores one (messages, queue, archives, Send Log). */
    public const SUBJECT_MAX = 250;

    /** For the administrator, after "The message was not queued/sent because". */
    public const BLANK = 'it is blank: it has no text and no image in its HTML or text part';

    /**
     * Blank means no visible text in either part and no image in the HTML
     * part: markup alone (the empty paragraph an editor leaves, a style
     * block, a comment) is not content.
     */
    public static function isBlank(string $html, string $text): bool
    {
        $html = (string) preg_replace(['#<(style|script|head|title)\b[^>]*>.*?</\1\s*>#is', '/<!--.*?-->/s'], '', $html);
        return self::visible($text) === ''
            && self::visible(strip_tags($html)) === ''
            && preg_match('/<img\b/i', $html) !== 1;
    }

    /** $text without white space of any kind (also non-breaking and zero-width). */
    private static function visible(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $text) ?? trim($text);
    }
}
