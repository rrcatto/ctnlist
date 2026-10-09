<?php

declare(strict_types=1);

namespace App\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * An open connection to one SmtpServer. Opening starts SMTP transports, so
 * connection failures surface before any message is built (the v5 OpenSMTP
 * contract). Not shared: create one per use.
 */
final class MailConnection
{
    private ?TransportInterface $transport = null;

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
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('ctnlist mail error: {message}', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /** Hand the message to the transport; false (and logged) on failure. */
    public function send(Email $email): bool
    {
        $transport = $this->transport;
        if ($transport === null) {
            return false;
        }
        try {
            $transport->send($email);
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
    }
}
