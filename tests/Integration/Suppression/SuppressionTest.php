<?php

declare(strict_types=1);

namespace App\Tests\Integration\Suppression;

use App\Config\SiteConfig;
use App\Suppression\BanlistSuppressionChecker;
use App\Suppression\NullSuppressionChecker;
use App\Suppression\SuppressionChecker;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;

final class SuppressionTest extends IntegrationTestCase
{
    private ?Connection $banlist = null;

    protected function tearDown(): void
    {
        if ($this->banlist?->isTransactionActive()) {
            $this->banlist->rollBack();
        }
        parent::tearDown();
    }

    public function testTestEnvironmentUsesTheNullChecker(): void
    {
        $checker = $this->service(SuppressionChecker::class);
        self::assertInstanceOf(NullSuppressionChecker::class, $checker);
        self::assertFalse($checker->isSuppressed('Jane@Example.com'));
        self::assertTrue($checker->isSuppressed('postmaster@example.com'), 'unusable addresses are always suppressed');
        self::assertFalse($checker->suppressEmail('jane@example.com', 'USER', ''), 'nothing can be recorded');
    }

    public function testBanlistEmailAndDomainSuppression(): void
    {
        $checker = $this->banlistChecker();
        self::assertFalse($checker->isSuppressed('jane@example.com'));

        self::assertTrue($checker->suppressEmail('jane@example.com', 'USER', 'asked'));
        self::assertTrue($checker->isSuppressed('Jane@Example.COM'), 'normalised before lookup');
        self::assertFalse($checker->isSuppressed('john@example.com'));

        self::assertTrue($checker->suppressDomain('spam.example', 'SPAM'));
        self::assertTrue($checker->isSuppressed('anyone@spam.example'));

        $row = $this->banlist->fetchAssociative("SELECT gu_api, gu_domain, gu_type, gu_reason FROM globalunsubscribe WHERE gu_email = 'jane@example.com'");
        self::assertSame(['gu_api' => 'test-instance', 'gu_domain' => 'ctnlist.test', 'gu_type' => 'USER', 'gu_reason' => 'asked'], $row);
    }

    public function testBanlistReactivatesAndRejectsUnknownTypes(): void
    {
        $checker = $this->banlistChecker();
        $checker->suppressEmail('jane@example.com', 'USER', 'first');
        $this->banlist->executeStatement("UPDATE globalunsubscribe SET gu_active = 0 WHERE gu_email = 'jane@example.com'");
        self::assertFalse($checker->isSuppressed('jane@example.com'), 'inactive records do not suppress');

        self::assertTrue($checker->suppressEmail('jane@example.com', 'SPAM-ADMIN', 'again'));
        self::assertTrue($checker->isSuppressed('jane@example.com'), 'reactivated');
        self::assertFalse($checker->suppressEmail('x@example.com', 'NOT-A-TYPE', ''), 'CHECK constraint failure is reported, not thrown');
    }

    private function banlistChecker(): BanlistSuppressionChecker
    {
        $file = dirname(__DIR__, 3) . '/' . getenv('BANLIST_TEST_ENV');
        $env = (new Dotenv())->parse((string) file_get_contents($file));
        $this->banlist = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $env['GDB_HOST'],
            'port' => (int) $env['GDB_PORT'],
            'dbname' => $env['GDB_NAME'],
            'user' => $env['GDB_USER'],
            'password' => $env['GDB_PASS'],
        ]);
        $this->banlist->beginTransaction();
        $site = SiteConfig::fromEnvironment(['APP_INSTANCE_ID' => 'test-instance', 'APP_DOMAIN' => 'ctnlist.test'], '/tmp');
        return new BanlistSuppressionChecker($this->banlist, $site, new NullLogger());
    }
}
