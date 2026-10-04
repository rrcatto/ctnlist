<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Config\SiteConfig;
use App\Mail\SmtpServer;
use App\Mail\SmtpServerPool;
use PHPUnit\Framework\TestCase;

final class SmtpServerPoolTest extends TestCase
{
    public function testCampaignsPreferActiveJsonServersInOrder(): void
    {
        $pool = $this->pool('smtp://dsn', '[{"active":0,"dsn":"smtp://off"},{"active":1,"dsn":"smtp://a","batchsize":50,"delay":3,"sendrate":20},{"active":1,"host":"mail.example","port":465,"user":"u@x","pass":"p w","enc":"ssl"}]');
        $servers = $pool->campaignServers();

        self::assertCount(2, $servers);
        self::assertEquals(new SmtpServer('smtp://a', 50, 3, 20), $servers[0]);
        self::assertSame('smtps://u%40x:p%20w@mail.example:465', $servers[1]->dsn, 'v5 host/port/user/pass/enc fields');
        self::assertSame([600, 10, 13], [$servers[1]->batchSize, $servers[1]->delay, $servers[1]->sendRate], 'v5 defaults');
        self::assertSame('smtp://dsn', $pool->transactionalServer()?->dsn, 'transactional mail prefers MAILER_DSN');
    }

    public function testMailerDsnAloneAndTls(): void
    {
        $pool = $this->pool('smtp://dsn', '[]');
        self::assertEquals([new SmtpServer('smtp://dsn', 100, 2, 13)], $pool->campaignServers());

        $tls = SmtpServer::fromConfig(['host' => 'h', 'port' => 587, 'enc' => 'tls'], 5);
        self::assertSame('smtp://h:587?require_tls=true', $tls->dsn);
        self::assertSame(5, $tls->sendRate);
    }

    public function testTransactionalFallsBackToTheFirstActiveJsonServer(): void
    {
        self::assertSame('smtp://a', $this->pool('', '[{"active":1,"dsn":"smtp://a"}]')->transactionalServer()?->dsn);
        self::assertNull($this->pool('', '[{"active":0,"dsn":"smtp://a"}]')->transactionalServer());
        self::assertSame([], $this->pool('', '[]')->campaignServers());
    }

    private function pool(string $mailerDsn, string $serversJson): SmtpServerPool
    {
        $site = SiteConfig::fromEnvironment(['MAIL_SMTP_SERVERS_JSON' => $serversJson], '/tmp');
        return new SmtpServerPool($site, $mailerDsn, 100, 2);
    }
}
