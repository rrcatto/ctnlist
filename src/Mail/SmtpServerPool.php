<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\SiteConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The configured transports. Campaign queues fail over across the active
 * MAIL_SMTP_SERVERS_JSON entries (or MAILER_DSN alone); transactional mail
 * prefers MAILER_DSN and falls back to the first JSON entry.
 */
final class SmtpServerPool
{
    public function __construct(
        private readonly SiteConfig $site,
        #[Autowire(env: 'MAILER_DSN')] private readonly string $mailerDsn,
        #[Autowire(env: 'int:MAIL_BATCH_SIZE')] private readonly int $batchSize,
        #[Autowire(env: 'int:MAIL_BATCH_DELAY')] private readonly int $batchDelay,
    ) {
    }

    /** @return list<SmtpServer> in failover order */
    public function campaignServers(): array
    {
        $servers = $this->activeJsonServers();
        if ($servers !== []) {
            return $servers;
        }
        return trim($this->mailerDsn) === '' ? [] : [$this->mailerDsnServer()];
    }

    public function transactionalServer(): ?SmtpServer
    {
        if (trim($this->mailerDsn) !== '') {
            return $this->mailerDsnServer();
        }
        $first = $this->site->smtpServers[0] ?? null;
        return is_array($first) && (int) ($first['active'] ?? 0) === 1
            ? SmtpServer::fromConfig($first, $this->site->emailsPerMinute)
            : null;
    }

    /** @return list<SmtpServer> */
    private function activeJsonServers(): array
    {
        $servers = [];
        foreach ($this->site->smtpServers as $entry) {
            if ((int) ($entry['active'] ?? 0) === 1) {
                $servers[] = SmtpServer::fromConfig($entry, $this->site->emailsPerMinute);
            }
        }
        return $servers;
    }

    private function mailerDsnServer(): SmtpServer
    {
        return new SmtpServer(trim($this->mailerDsn), max(1, $this->batchSize), max(0, $this->batchDelay), max(0, $this->site->emailsPerMinute));
    }
}
