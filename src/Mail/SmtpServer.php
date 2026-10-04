<?php

declare(strict_types=1);

namespace App\Mail;

/** One configured mail transport with its v5 batching and throttling settings. */
final class SmtpServer
{
    public function __construct(
        public readonly string $dsn,
        /** Queue rows fetched per batch. */
        public readonly int $batchSize = 600,
        /** Seconds to pause between batches and before a retry. */
        public readonly int $delay = 0,
        /** Maximum messages per minute; 0 disables throttling. */
        public readonly int $sendRate = 0,
    ) {
    }

    /**
     * From a MAIL_SMTP_SERVERS_JSON entry: either `dsn`, or the v5 fields
     * host/port/user/pass/enc (ssl = smtps, tls = require_tls).
     *
     * @param array<string, mixed> $entry
     */
    public static function fromConfig(array $entry, int $defaultSendRate): self
    {
        $dsn = trim((string) ($entry['dsn'] ?? ''));
        if ($dsn === '') {
            $host = trim((string) ($entry['host'] ?? ''));
            if ($host === '') {
                throw new \InvalidArgumentException('SMTP host is missing.');
            }
            $encryption = strtolower(trim((string) ($entry['enc'] ?? '')));
            $user = (string) ($entry['user'] ?? '');
            $auth = $user !== '' ? rawurlencode($user) . ':' . rawurlencode((string) ($entry['pass'] ?? '')) . '@' : '';
            $dsn = ($encryption === 'ssl' ? 'smtps' : 'smtp') . '://' . $auth . $host . ':' . (int) ($entry['port'] ?? 25)
                . ($encryption === 'tls' ? '?require_tls=true' : '');
        }
        return new self(
            $dsn,
            max(1, (int) ($entry['batchsize'] ?? 600)),
            max(0, (int) ($entry['delay'] ?? 10)),
            max(0, (int) ($entry['sendrate'] ?? $defaultSendRate)),
        );
    }
}
