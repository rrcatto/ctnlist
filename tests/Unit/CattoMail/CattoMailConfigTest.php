<?php

declare(strict_types=1);

namespace App\Tests\Unit\CattoMail;

use App\CattoMail\CattoMailConfig;
use App\CattoMail\UnsubscribeLinks;
use App\Config\SiteConfig;
use PHPUnit\Framework\TestCase;

final class CattoMailConfigTest extends TestCase
{
    public function testSecretsStayOutOfDebugOutput(): void
    {
        $config = new CattoMailConfig('https://mail.example.com/v1/', 'shk_live_secret', 'whsec_a', 'whsec_b', true);
        self::assertSame('https://mail.example.com/v1', $config->apiRoot());
        self::assertSame(['whsec_a', 'whsec_b'], $config->webhookSecrets());
        $dump = print_r($config, true) . var_export($config->__debugInfo(), true);
        foreach (['shk_live_secret', 'whsec_a', 'whsec_b'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        $this->expectException(\LogicException::class);
        serialize($config);
    }

    public function testProblems(): void
    {
        self::assertStringContainsString('not configured', (string) (new CattoMailConfig('', '', ''))->problem());
        self::assertStringContainsString('https', (string) (new CattoMailConfig('http://mail.example.com', 'k', ''))->problem());
        self::assertNull((new CattoMailConfig('https://mail.example.com', 'k', ''))->problem());
        self::assertSame('https://mail.example.com/v1', (new CattoMailConfig('https://mail.example.com', 'k', ''))->apiRoot());
    }

    public function testOneClickUnsubscribeLinksAreSignedAndUntracked(): void
    {
        $site = SiteConfig::fromEnvironment(['APP_BASE_URL' => 'https://lists.example.com/'], '/tmp');
        $links = new UnsubscribeLinks($site, 'app-secret');
        $uuid = '0199a000-0000-7000-8000-000000000001';
        $url = $links->url($uuid, 'news', str_repeat('a', 32));
        self::assertMatchesRegularExpression('#^https://lists\.example\.com/unsubscribe-link/' . $uuid . '/NEWS/a{32}/[A-Za-z0-9_-]{32}$#', $url);
        self::assertStringNotContainsString('?', $url, 'no tracking parameters');
        $signature = substr($url, strrpos($url, '/') + 1);
        self::assertTrue($links->isValid($uuid, 'NEWS', str_repeat('a', 32), $signature));
        self::assertFalse($links->isValid($uuid, 'OTHER', str_repeat('a', 32), $signature), 'another list');
        self::assertFalse($links->isValid('0199a000-0000-7000-8000-000000000002', 'NEWS', str_repeat('a', 32), $signature), 'another subscriber');
        self::assertFalse((new UnsubscribeLinks($site, 'other-secret'))->isValid($uuid, 'NEWS', str_repeat('a', 32), $signature), 'another installation');
        self::assertTrue($links->isUsable());
        self::assertFalse((new UnsubscribeLinks(SiteConfig::fromEnvironment(['APP_BASE_URL' => 'http://localhost/'], '/tmp'), 's'))->isUsable(), 'catto-mail needs https');
        self::assertTrue($links->isValid($uuid, 'NEWS', '-', substr($links->url($uuid, 'NEWS', ''), -32)), 'no message');
    }
}
