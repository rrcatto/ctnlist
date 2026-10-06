<?php

declare(strict_types=1);

namespace App\Mail;

/** The transactional SMTP transport (MAILER_DSN) and its throttle. */
final class SmtpServer
{
    public function __construct(
        public readonly string $dsn,
        /** Maximum messages per minute; 0 disables throttling. */
        public readonly int $sendRate = 0,
    ) {
    }
}
