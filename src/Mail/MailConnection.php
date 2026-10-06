<?php

declare(strict_types=1);

namespace App\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * An open connection to one SmtpServer, throttled to its send rate. Opening
 * starts SMTP transports, so connection failures surface before any message
 * is built (the v5 OpenSMTP contract). Not shared: create one per use.
 */
final class MailConnection
{
    private ?TransportInterface $transport = null;
    private ?SmtpServer $server = null;
    private float $lastSendAt = 0.0;

    public function __construct(
        #[Autowire(service: 'mailer.transport_factory')] private readonly Transport $transports,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function open(SmtpServer $server): bool
    {
        $this->close();
        try {
            $transport = $this->transports->fromString($server->dsn);
            if (method_exists($transport, 'start')) {
                $transport->start();
            }
            $this->transport = $transport;
            $this->server = $server;
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('ctnlist mail error: {message}', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function isOpen(): bool
    {
        return $this->transport !== null;
    }

    public function server(): ?SmtpServer
    {
        return $this->server;
    }

    /** Hand the message to the transport; false (and logged) on failure. */
    public function send(Email $email): bool
    {
        $transport = $this->transport;
        if ($transport === null) {
            return false;
        }
        try {
            $this->throttle();
            $transport->send($email);
            $this->lastSendAt = microtime(true);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('ctnlist mail error: {message}', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function close(): void
    {
        if ($this->transport !== null && method_exists($this->transport, 'stop')) {
            try {
                $this->transport->stop();
            } catch (\Throwable $e) {
                $this->logger->warning('ctnlist mail error on close: {message}', ['message' => $e->getMessage()]);
            }
        }
        $this->transport = null;
        $this->server = null;
        $this->lastSendAt = 0.0;
    }

    private function throttle(): void
    {
        $perMinute = $this->server->sendRate ?? 0;
        if ($perMinute <= 0 || $this->lastSendAt <= 0.0) {
            return;
        }
        $wait = 60 / $perMinute - (microtime(true) - $this->lastSendAt);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
    }
}
