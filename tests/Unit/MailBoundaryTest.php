<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Campaign content (queue sends, proofs, resends, forwards) goes only through
 * catto-mail (CattoMailSender); ctnlist's SMTP is for transactional mail. So
 * outside src/Mail nothing may reach an SMTP transport directly, and only
 * the transactional mailer's own methods are used.
 */
final class MailBoundaryTest extends TestCase
{
    public function testOnlyTheTransactionalMailerUsesSmtp(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || str_starts_with($path, $root . '/Mail/')) {
                continue;
            }
            $code = (string) file_get_contents($path);
            if (preg_match('/\b(MailConnection|MailConnectionFactory|SmtpServerPool|MailerInterface|TransportInterface|Symfony\\\\Component\\\\Mailer)\b/', $code) === 1) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }
        self::assertSame([], $offenders, 'SMTP used outside src/Mail');
    }
}
