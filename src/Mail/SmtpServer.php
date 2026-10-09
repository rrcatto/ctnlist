<?php

declare(strict_types=1);

namespace App\Mail;

/** The transactional SMTP transport (MAILER_DSN). */
final class SmtpServer
{
    public function __construct(
        public readonly string $dsn,
    ) {
    }
}
