<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\CattoMailActivity;
use App\CattoMail\CattoMailWorker;
use App\CattoMail\GlobalOptOut;
use App\Command\CattoMailWorkCommand;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The worker as cron runs it: one at a time, counts and failures, exit status. */
final class CattoMailWorkerTest extends CattoMailTestCase
{
    public function testAnOverlappingRunSkips(): void
    {
        // Another worker (another database session) holds the lock.
        $other = DriverManager::getConnection($this->db->getParams());
        self::assertTrue((bool) $other->fetchOne("SELECT pg_try_advisory_lock(hashtext('ctnlist:cattomail:worker'))"));
        try {
            self::assertSame(['skipped (another worker is running)' => 1], $this->service(CattoMailWorker::class)->run());
        } finally {
            $other->fetchOne("SELECT pg_advisory_unlock(hashtext('ctnlist:cattomail:worker'))");
            $other->close();
        }
        self::assertArrayHasKey('failures', $this->service(CattoMailWorker::class)->run(), 'runs once the other one is done');
    }

    public function testFailuresAreCountedAndGiveANonZeroExitStatus(): void
    {
        $command = new CommandTester($this->service(CattoMailWorkCommand::class));
        self::assertSame(Command::SUCCESS, $command->execute([]), 'nothing to do is not a failure');
        self::assertSame('', $command->getDisplay(), 'quiet when idle (cron mails only what matters)');

        $id = $this->createSubscriber('ann@example.com');
        $this->fake->failNext('POST /global-suppressions', 'timeout', times: 6);
        $this->service(GlobalOptOut::class)->request($id, $this->subscriberUuid($id), 'ann@example.com');

        self::assertSame(Command::FAILURE, $command->execute([]));
        $display = $command->getDisplay();
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d catto-mail worker: .*opt-outs reported 0.*failures 1/', $display);
        self::assertStringNotContainsString(self::SECRET, $display);

        $activity = $this->service(CattoMailActivity::class)->snapshot();
        self::assertNotNull($activity['worker_run']);
        self::assertStringContainsString('failures: 1', (string) $activity['worker_summary']);
        self::assertStringContainsString('/global-suppressions', (string) $activity['last_error'], 'the last API error, for the status page');

        self::assertSame(Command::SUCCESS, $command->execute([]), 'reported on the next pass');
        self::assertStringContainsString('opt-outs reported 1', $command->getDisplay());
        self::assertNotNull($this->service(CattoMailActivity::class)->snapshot()['last_ok']);
    }
}
