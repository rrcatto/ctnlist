<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\RuntimeSettings;
use App\Config\SiteConfig;

/**
 * Builds the SMTP pool from the effective settings (database override, .env,
 * default). The pool service is lazy, so stored secrets are decrypted only
 * when mail is sent; a secret that cannot be decrypted fails that send loudly
 * (SettingsCipherException) rather than falling back to other credentials.
 */
final class SmtpServerPoolFactory
{
    public function __construct(
        private readonly SiteConfig $site,
        private readonly RuntimeSettings $settings,
    ) {
    }

    public function __invoke(): SmtpServerPool
    {
        $servers = json_decode($this->settings->get('MAIL_SMTP_SERVERS_JSON', '[]'), true);
        return new SmtpServerPool(
            $this->site,
            $this->settings->get('MAILER_DSN'),
            $this->settings->int('MAIL_BATCH_SIZE', 600),
            $this->settings->int('MAIL_BATCH_DELAY', 0),
            is_array($servers) ? array_values(array_filter($servers, 'is_array')) : [],
        );
    }
}
