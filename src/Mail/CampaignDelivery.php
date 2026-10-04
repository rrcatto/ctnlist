<?php

declare(strict_types=1);

namespace App\Mail;

/** One campaign message, rendered for one subscriber, with its list context. */
final class CampaignDelivery
{
    public function __construct(
        public readonly string $muid,
        public readonly string $subject,
        public readonly string $fromName,
        public readonly string $fromAddress,
        public readonly string $subscriberUuid,
        public readonly string $email,
        public readonly string $name,
        /** List context ('' for a proof of a draft without lists: no List-Unsubscribe). */
        public readonly string $listShortcode,
        public readonly string $html,
        public readonly string $text,
    ) {
    }
}
