<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\SiteConfig;
use PHPUnit\Framework\TestCase;

final class SiteConfigTest extends TestCase
{
    public function testDefaultsAndFallbacks(): void
    {
        $site = SiteConfig::fromEnvironment([
            'APP_BASE_URL' => 'https://example.com',
            'APP_ADMIN_EMAIL' => 'owner@example.com',
            'APP_ORGANISATION' => '',
            'MAIL_SMTP_SERVERS_JSON' => '[{"dsn":"smtp://a"},"junk"]',
            'APP_ARCHIVE_ENABLED' => 'true',
        ], '/var/www/example/');

        self::assertSame('https://example.com/', $site->baseUrl, 'trailing slash added');
        self::assertSame('ctnlist', $site->listName);
        self::assertSame('owner@example.com', $site->adminEmail, 'falls back to APP_ADMIN_EMAIL');
        self::assertSame('owner@example.com', $site->contactEmail, 'falls back to the admin address');
        self::assertSame('owner@example.com', $site->testEmail);
        self::assertSame('ctnlist', $site->contactName, 'empty values count as unset');
        self::assertSame('/var/www/example/logs/contact.log', $site->contactLogFile);
        self::assertSame([['dsn' => 'smtp://a']], $site->smtpServers);
        self::assertSame(13, $site->emailsPerMinute);
        self::assertTrue($site->archiveEnabled);
    }

    public function testExplicitValuesWin(): void
    {
        $site = SiteConfig::fromEnvironment([
            'APP_ADMIN_EMAIL' => 'owner@example.com',
            'MAIL_ADMIN_ADDRESS' => 'admin@example.com',
            'CONTACT_MAIL_ADDRESS' => 'contact@example.com',
            'MAIL_RATE_PER_MINUTE' => '0',
        ], '/srv');

        self::assertSame('admin@example.com', $site->adminEmail);
        self::assertSame('contact@example.com', $site->contactEmail);
        self::assertSame(0, $site->emailsPerMinute);
        self::assertFalse($site->archiveEnabled);
    }
}
