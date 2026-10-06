<?php

declare(strict_types=1);

namespace App\CattoMail;

/** One recipient's final, fully rendered content (catto-mail performs no mail merge). */
final class OutgoingRecipient
{
    public function __construct(
        public readonly string $email,
        /** The subscriber it was rendered for; null for a proof to an arbitrary address. */
        public readonly ?string $subscriberUuid,
        /** Send Log type: MESSAGE, PROOF, RESEND or FORWARD-MESSAGE. */
        public readonly string $type,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
    ) {
    }
}
