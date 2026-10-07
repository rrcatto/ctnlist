<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\ProductionReadiness;
use PHPUnit\Framework\TestCase;

final class ProductionReadinessTest extends TestCase
{
    private const SECRET = '9f2c4e7a1b3d5f60718293a4b5c6d7e8f9a0b1c2d3e4f5061728394a5b6c7d8e';

    public function testAProductionReadyConfigurationPasses(): void
    {
        $findings = ProductionReadiness::check(self::ready());
        self::assertSame([], array_values(array_filter($findings, static fn(array $f): bool => $f[0] !== 'OK')), 'no warnings or errors');
    }

    /**
     * @param array<string, mixed> $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafe')]
    public function testUnsafeValuesAreReported(array $change, string $level, string $expected): void
    {
        /** @var array{environment: string, debug: bool, app_secret: string, base_url: string, mailer_dsn: string, suppression: string, connect_host: string, trusted_proxies: string, cattomail_secrets: array<string, string>, addresses: array<string, string>} $values */
        $values = array_replace(self::ready(), $change);
        $reported = array_values(array_filter(ProductionReadiness::check($values), static fn(array $f): bool => $f[0] === $level && str_contains($f[1], $expected)));
        self::assertCount(1, $reported, $level . ' ' . $expected);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function unsafe(): iterable
    {
        yield 'dev environment' => [['environment' => 'dev'], 'ERROR', 'APP_ENV is "dev"'];
        yield 'debug' => [['debug' => true], 'ERROR', 'APP_DEBUG is on'];
        yield 'empty secret' => [['app_secret' => ''], 'ERROR', 'APP_SECRET'];
        yield 'short secret' => [['app_secret' => 'a1b2c3d4e5'], 'ERROR', 'APP_SECRET'];
        yield 'Symfony placeholder' => [['app_secret' => 'ThisTokenIsNotSoSecretChangeIt'], 'ERROR', 'APP_SECRET'];
        yield 'repetitive secret' => [['app_secret' => str_repeat('ab', 32)], 'ERROR', 'APP_SECRET'];
        yield 'http base URL' => [['base_url' => 'http://lists.ctnlist.org/'], 'ERROR', 'APP_BASE_URL'];
        yield 'localhost' => [['base_url' => 'https://localhost:8543/'], 'ERROR', 'APP_BASE_URL'];
        yield 'loopback address' => [['base_url' => 'https://127.0.0.1/'], 'ERROR', 'APP_BASE_URL'];
        yield 'example domain' => [['base_url' => 'https://example.com/'], 'ERROR', 'APP_BASE_URL'];
        yield 'development domain' => [['base_url' => 'https://ctnlist.test/'], 'ERROR', 'APP_BASE_URL'];
        yield 'no mail transport' => [['mailer_dsn' => ''], 'ERROR', 'MAILER_DSN is not set'];
        yield 'null transport' => [['mailer_dsn' => 'null://null'], 'ERROR', 'null transport'];
        yield 'example DSN' => [['mailer_dsn' => 'smtps://username:password@mail.example.com:465'], 'ERROR', 'example server'];
        yield 'no suppression' => [['suppression' => 'none'], 'ERROR', 'SUPPRESSION_PROVIDER=none'];
        yield 'development connect host' => [['connect_host' => 'cattomail-dev'], 'WARN', 'CATTOMAIL_API_CONNECT_HOST'];
        yield 'trust everyone' => [['trusted_proxies' => '127.0.0.1,0.0.0.0/0'], 'WARN', 'TRUSTED_PROXIES trusts every address'];
        yield 'placeholder API key' => [['cattomail_secrets' => ['CATTOMAIL_API_KEY' => 'change-me']], 'ERROR', 'CATTOMAIL_API_KEY is a placeholder'];
        yield 'example address' => [['addresses' => ['MAIL_FROM_ADDRESS' => 'info@example.com']], 'WARN', 'MAIL_FROM_ADDRESS is still the example address'];
    }

    public function testFindingsNeverContainSecretValues(): void
    {
        $values = array_replace(self::ready(), ['debug' => true, 'app_secret' => 'tooShortButSecret1', 'mailer_dsn' => 'smtp://user:Pa55word!@smtp.example.com:587',
            'cattomail_secrets' => ['CATTOMAIL_API_KEY' => 'changeme-key-value', 'CATTOMAIL_WEBHOOK_SECRET' => 'whsec_Real_Secret_Value_123']]);
        $text = implode("\n", array_column(ProductionReadiness::check($values), 1));
        foreach (['tooShortButSecret1', 'Pa55word', 'changeme-key-value', 'whsec_Real_Secret_Value_123'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    public function testPlaceholderDetection(): void
    {
        foreach (['', 'changeme', '!ChangeMe!', 'CHANGE_ME_PLEASE', 'secret', 'Password', 'ThisTokenIsNotSoSecretChangeIt'] as $placeholder) {
            self::assertTrue(ProductionReadiness::isPlaceholder($placeholder), $placeholder);
        }
        self::assertFalse(ProductionReadiness::isPlaceholder('ck_live_7f3a9b2c4d5e6f708192'));
        self::assertTrue(ProductionReadiness::strongSecret(self::SECRET));
        self::assertTrue(ProductionReadiness::strongSecret(base64_encode(random_bytes(32))));
    }

    /** @return array{environment: string, debug: bool, app_secret: string, base_url: string, mailer_dsn: string, suppression: string, connect_host: string, trusted_proxies: string, cattomail_secrets: array<string, string>, addresses: array<string, string>} */
    private static function ready(): array
    {
        return [
            'environment' => 'prod',
            'debug' => false,
            'app_secret' => self::SECRET,
            'base_url' => 'https://lists.ctnlist.org/',
            'mailer_dsn' => 'smtps://notify%40ctnlist.org:Xy7%21pq@smtp.mailprovider.net:465',
            'suppression' => 'banlist',
            'connect_host' => '',
            'trusted_proxies' => '127.0.0.1',
            'cattomail_secrets' => ['CATTOMAIL_API_KEY' => 'ck_live_7f3a9b2c4d5e6f708192', 'CATTOMAIL_WEBHOOK_SECRET' => 'whsec_4f8a2c9e1b7d3f5a'],
            'addresses' => ['MAIL_FROM_ADDRESS' => 'info@ctnlist.org', 'APP_ADMIN_EMAIL' => 'admin@ctnlist.org'],
        ];
    }
}
