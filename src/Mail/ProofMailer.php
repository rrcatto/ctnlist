<?php

declare(strict_types=1);

namespace App\Mail;

use App\Campaign\MessageContent;
use App\CattoMail\OutgoingMessage;
use App\Log\SendLog;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Proofs sent straight to a Mailpit instance instead of through the mail
 * service: MAIL_PROOF_SMTP (.env) is the host and port of Mailpit's SMTP
 * listener, e.g. 127.0.0.1:1025. Mailpit keeps every message it is handed
 * and delivers none, so the proof is read on Mailpit's web page and never
 * reaches the address it is made out to. With the setting empty this class
 * does nothing and proofs go through the mail service (MessageService).
 *
 * Proofs only: queue sends, resends and forwards always go through the mail
 * service. A proof handed to Mailpit is written to the Send Log as PROOF, as
 * one handed to the mail service is.
 */
final class ProofMailer
{
    public function __construct(
        #[Autowire('%env(default::MAIL_PROOF_SMTP)%')] private readonly ?string $setting,
        private readonly MailConnectionFactory $connections,
        private readonly SendLog $sendLog,
    ) {
    }

    /** Proofs go to Mailpit (the setting is not empty; it may still be unusable: problem()). */
    public function enabled(): bool
    {
        return trim((string) $this->setting) !== '';
    }

    /** The setting as written, for pages and messages. */
    public function target(): string
    {
        return trim((string) $this->setting);
    }

    /** Why the setting cannot be used, or null. */
    public function problem(): ?string
    {
        return !$this->enabled() || self::parse($this->target()) !== null ? null
            : 'MAIL_PROOF_SMTP must be the host and port of Mailpit\'s SMTP listener, for example 127.0.0.1:1025.';
    }

    /** Mailpit is on this server (a loopback address): nothing leaves the machine. */
    public function local(): bool
    {
        $parsed = self::parse($this->target());
        return $parsed !== null && (in_array($parsed['host'], ['localhost', '::1'], true) || str_starts_with($parsed['host'], '127.'));
    }

    /**
     * Hand one proof to Mailpit and record it in the Send Log.
     *
     * @return string|null null when Mailpit took it; otherwise why not, for the administrator
     */
    public function send(OutgoingMessage $message, string $to, string $subject, string $html, string $text): ?string
    {
        $parsed = self::parse($this->target());
        if ($parsed === null) {
            return (string) $this->problem();
        }
        if (trim($message->senderEmail) === '') {
            return 'the message has no From address (set one on the message, or MAIL_FROM_ADDRESS in Settings).';
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message->senderName));
        $email = (new Email())
            ->from($name === '' ? new Address(trim($message->senderEmail)) : new Address(trim($message->senderEmail), $name))
            ->to(new Address($to))
            ->subject($subject);
        if (trim($html) !== '') {
            $email->html($html);
        }
        if (trim($text) !== '' || trim($html) === '') {
            $email->text($text);
        }

        $host = str_contains($parsed['host'], ':') ? '[' . $parsed['host'] . ']' : $parsed['host'];
        $connection = $this->connections->create();
        // Mailpit on a loopback port speaks plain SMTP; no TLS is negotiated and no credentials are sent.
        if (!$connection->open(new SmtpServer(sprintf('smtp://%s:%d?auto_tls=false', $host, $parsed['port'])))) {
            return 'Mailpit could not be reached at ' . $this->target() . ' (MAIL_PROOF_SMTP).';
        }
        try {
            if (!$connection->send($email)) {
                return 'Mailpit at ' . $this->target() . ' did not accept the proof.';
            }
        } finally {
            $connection->close();
        }
        // Handing the proof to Mailpit is its handoff (invariant: Send Log).
        $this->sendLog->record($message->muid, 'PROOF', $to, $message->listShortcode, mb_substr($subject, 0, MessageContent::SUBJECT_MAX));
        return null;
    }

    /**
     * host:port, [IPv6]:port or IPv4:port.
     *
     * @return array{host: string, port: int}|null
     */
    public static function parse(string $value): ?array
    {
        if (preg_match('/^(?:\[([0-9A-Fa-f:.]+)\]|([A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)):(\d{1,5})$/', trim($value), $m) !== 1) {
            return null;
        }
        $port = (int) $m[3];
        $host = $m[1] !== '' ? $m[1] : $m[2];
        if ($port < 1 || $port > 65535 || ($m[1] !== '' && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)) {
            return null;
        }
        return ['host' => strtolower($host), 'port' => $port];
    }
}
