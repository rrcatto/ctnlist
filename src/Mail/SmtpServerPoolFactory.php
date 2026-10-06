<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\RuntimeSettings;

/**
 * Builds the transactional SMTP server from the effective settings (database
 * override, .env, default). The service is lazy, so the stored secret is
 * decrypted only when mail is sent; a secret that cannot be decrypted fails
 * that send loudly (SettingsCipherException) rather than falling back.
 */
final class SmtpServerPoolFactory
{
    public function __construct(private readonly RuntimeSettings $settings)
    {
    }

    public function __invoke(): SmtpServerPool
    {
        return new SmtpServerPool($this->settings->get('MAILER_DSN'), $this->settings->int('MAIL_RATE_PER_MINUTE', 13));
    }
}
