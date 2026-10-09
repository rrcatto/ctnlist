<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\SmtpServer;
use App\Mail\SmtpServerPool;
use PHPUnit\Framework\TestCase;

/** Transactional mail uses MAILER_DSN; campaign content goes to catto-mail instead. */
final class SmtpServerPoolTest extends TestCase
{
    public function testTransactionalServerIsMailerDsn(): void
    {
        self::assertEquals(new SmtpServer('smtp://dsn'), (new SmtpServerPool(' smtp://dsn '))->transactionalServer());
        self::assertNull((new SmtpServerPool(''))->transactionalServer(), 'no transactional mail without MAILER_DSN');
    }
}
