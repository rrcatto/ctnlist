<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\SiteConfig;

/**
 * The configured transports. Campaign queues fail over across the active
 * MAIL_SMTP_SERVERS_JSON entries (or MAILER_DSN alone); transactional mail
 * prefers MAILER_DSN and falls back to the first JSON entry. Built by
 * SmtpServerPoolFactory from the effective settings.
 */
final class SmtpServerPool
{
    /** @var list<array<string, mixed>> MAIL_SMTP_SERVERS_JSON entries */
    private readonly array $servers;

    /** @param list<array<string, mixed>>|null $servers the failover servers; null uses SiteConfig's (.env) */
    public function __construct(
        private readonly SiteConfig $site,
        private readonly string $mailerDsn,
        private readonly int $batchSize,
        private readonly int $batchDelay,
        ?array $servers = null,
    ) {
        $this->servers = $servers ?? $site->smtpServers;
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
        $first = $this->servers[0] ?? null;
        return is_array($first) && (int) ($first['active'] ?? 0) === 1
            ? SmtpServer::fromConfig($first, $this->site->emailsPerMinute)
            : null;
    }

    /** @return list<SmtpServer> */
    private function activeJsonServers(): array
    {
        $servers = [];
        foreach ($this->servers as $entry) {
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
