<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\CattoMail\CattoMailConfigFactory;
use App\Config\SiteConfig;
use App\Suppression\NullSuppressionChecker;
use App\Suppression\SuppressionCheckerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Development aids are refused or ignored outside APP_ENV dev and test. */
final class DevelopmentOnlyTest extends TestCase
{
    /** @return iterable<array{string, bool}> */
    public static function environments(): iterable
    {
        yield ['dev', true];
        yield ['test', true];
        yield ['prod', false];
    }

    #[DataProvider('environments')]
    public function testNoSuppressionProviderOnlyInDevelopment(string $environment, bool $allowed): void
    {
        $factory = new SuppressionCheckerFactory('none', '', '', '/tmp', $environment, SiteConfig::fromEnvironment([], '/tmp'), new NullLogger());
        if (!$allowed) {
            $this->expectExceptionMessage('SUPPRESSION_PROVIDER=none is only permitted when APP_ENV is dev or test.');
        }
        self::assertInstanceOf(NullSuppressionChecker::class, $factory->create());
    }

    #[DataProvider('environments')]
    public function testCattoMailConnectHostOnlyInDevelopment(string $environment, bool $allowed): void
    {
        $config = (new CattoMailConfigFactory('https://mail.example.com/v1', 'key', '', '', '', '', '', '', '', '', 'catto-dev', $environment))();
        self::assertSame($allowed ? 'catto-dev' : '', $config->connectHost);
    }

    /** The webhook address a connection asks for instead of APP_BASE_URL's: a development route only. */
    #[DataProvider('environments')]
    public function testCattoMailWebhookUrlOnlyInDevelopment(string $environment, bool $allowed): void
    {
        $config = (new CattoMailConfigFactory('', '', '', '', '', '', '', '', '', '', '', $environment, null, null, 'http://ctnlist-web/cattomail/webhook'))();
        self::assertSame($allowed ? 'http://ctnlist-web/cattomail/webhook' : '', $config->webhookUrl);
    }
}
