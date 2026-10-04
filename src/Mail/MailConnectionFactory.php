<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/** Hands out a fresh MailConnection for each use. */
final class MailConnectionFactory
{
    /** @param \Closure(): MailConnection $connection */
    public function __construct(
        #[AutowireServiceClosure(MailConnection::class)] private readonly \Closure $connection,
    ) {
    }

    public function create(): MailConnection
    {
        return ($this->connection)();
    }
}
