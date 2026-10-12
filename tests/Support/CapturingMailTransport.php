<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * An SMTP server stand-in for App\Mail\MailConnection: a transport factory
 * that accepts smtp:// addresses, records which address each connection was
 * opened to and every message handed over, and delivers nothing. With
 * $reachable false, opening a connection fails as a refused TCP connection
 * does.
 */
final class CapturingMailTransport implements TransportFactoryInterface
{
    public bool $reachable = true;
    /** @var list<array{host: string, port: ?int, options: array<string, mixed>}> every connection opened */
    public array $connections = [];
    /** @var list<Email> every message accepted */
    public array $messages = [];

    public function supports(Dsn $dsn): bool
    {
        return in_array($dsn->getScheme(), ['smtp', 'smtps'], true);
    }

    public function create(Dsn $dsn): TransportInterface
    {
        $server = $this;
        $address = ['host' => $dsn->getHost(), 'port' => $dsn->getPort(), 'options' => ['auto_tls' => $dsn->getOption('auto_tls'), 'user' => $dsn->getUser()]];
        return new class ($server, $address) extends AbstractTransport {
            /** @param array{host: string, port: ?int, options: array<string, mixed>} $address */
            public function __construct(private readonly CapturingMailTransport $server, private readonly array $address)
            {
                parent::__construct();
            }

            public function start(): void
            {
                if (!$this->server->reachable) {
                    throw new TransportException(sprintf('Connection could not be established with host "%s:%d".', $this->address['host'], (int) $this->address['port']));
                }
                $this->server->connections[] = $this->address;
            }

            protected function doSend(SentMessage $message): void
            {
                $original = $message->getOriginalMessage();
                if ($original instanceof Email) {
                    $this->server->messages[] = $original;
                }
            }

            public function __toString(): string
            {
                return 'capture://' . $this->address['host'];
            }
        };
    }
}
