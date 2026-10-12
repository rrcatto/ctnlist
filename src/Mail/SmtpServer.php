<?php

declare(strict_types=1);

namespace App\Mail;

/** An SMTP transport: the transactional one (MAILER_DSN), or Mailpit for proofs (ProofMailer, MAIL_PROOF_SMTP). */
final class SmtpServer
{
    public function __construct(
        public readonly string $dsn,
    ) {
    }
}
