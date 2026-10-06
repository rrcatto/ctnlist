<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * The SMTP server for transactional mail (sign-in links, invitations,
 * notifications, contact acknowledgements): MAILER_DSN from the effective
 * settings. Campaign content is not sent here: it goes to catto-mail
 * (App\CattoMail\CattoMailSender). Built by SmtpServerPoolFactory.
 */
final class SmtpServerPool
{
    public function __construct(
        private readonly string $mailerDsn,
        private readonly int $sendRate,
    ) {
    }

    public function transactionalServer(): ?SmtpServer
    {
        return trim($this->mailerDsn) === '' ? null : new SmtpServer(trim($this->mailerDsn), max(0, $this->sendRate));
    }
}
