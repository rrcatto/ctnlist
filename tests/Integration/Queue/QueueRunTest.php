<?php

declare(strict_types=1);

namespace App\Tests\Integration\Queue;

use App\Queue\QueueBuilder;
use App\Queue\QueueProcessor;
use App\Queue\QueueRun;
use App\Repository\OptionRepository;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\FakeCattoMail;
use Doctrine\DBAL\DriverManager;

/** Start sending records a run, which is sent after the page has answered, and the Sending page follows it (QueueRun). */
final class QueueRunTest extends IntegrationTestCase
{
    private FakeCattoMail $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = $this->fakeCattoMail();
    }

    public function testARequestedRunIsSentAfterwardsAndItsOutcomeKept(): void
    {
        $muid = $this->queued(3);
        $run = $this->service(QueueRun::class);
        self::assertNull($run->view(), 'no run yet');

        self::assertTrue($run->request($muid, 'Campaign', 1000, 'admin-uuid'));
        $view = self::viewOf($run);
        self::assertSame([QueueRun::STARTING, 'Campaign', 1000, 0], [$view['state'], $view['subject'], $view['limit'], $view['staged']]);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'), 'nothing is sent by the request itself');
        self::assertFalse($run->request('', '', 10, 'another-admin'), 'one run at a time');

        $run->run();
        $view = self::viewOf($run);
        self::assertSame([QueueRun::FINISHED, 3, 3, 'Handed 3 message(s) to catto-mail.'], [$view['state'], $view['staged'], $view['handed_off'], $view['outcome']]);
        self::assertNotNull($view['started_at']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'));

        $run->run();
        self::assertSame(1, count($this->fake->sendJobs), 'a finished run is not sent again');
        self::assertTrue($run->request('', '', 10, 'admin-uuid'), 'the next run can be requested');
    }

    public function testTheProcessorReportsProgressAsItGoes(): void
    {
        $muid = $this->queued(3);
        $seen = [];
        $this->service(QueueProcessor::class)->process($muid, 1000, function (int $staged) use (&$seen): void {
            $seen[] = $staged;
        });
        self::assertSame([1, 2, 3], $seen);
    }

    public function testARunThatDiedOrNeverBeganIsShownAsSuch(): void
    {
        $run = $this->service(QueueRun::class);
        $options = $this->service(OptionRepository::class);
        $recorded = static fn(string $state, string $requestedAt): string => (string) json_encode(['muid' => '', 'subject' => '', 'limit' => 10, 'by' => 'x',
            'state' => $state, 'requested_at' => $requestedAt, 'started_at' => null, 'finished_at' => null, 'staged' => 4, 'handed_off' => 0, 'outcome' => null]);

        // Recorded as running while another process holds the queue lock: it is running.
        $other = DriverManager::getConnection($this->db->getParams());
        self::assertTrue((bool) $other->fetchOne("SELECT pg_try_advisory_lock(hashtext('ctnlist:queue'))"));
        try {
            $options->set('QueueRun', $recorded(QueueRun::RUNNING, date('Y-m-d H:i:s')));
            self::assertSame(QueueRun::RUNNING, self::viewOf($run)['state']);
            self::assertFalse($run->request('', '', 10, 'admin-uuid'));
        } finally {
            $other->fetchOne("SELECT pg_advisory_unlock(hashtext('ctnlist:queue'))");
            $other->close();
        }

        // Nothing holds the lock: the process that was sending was ended.
        self::assertSame(QueueRun::INTERRUPTED, self::viewOf($run)['state']);
        self::assertTrue($run->request('', '', 10, 'admin-uuid'), 'it can be started again');

        // Requested long ago and never begun.
        $options->set('QueueRun', $recorded(QueueRun::STARTING, date('Y-m-d H:i:s', time() - 600)));
        self::assertSame(QueueRun::NOT_STARTED, self::viewOf($run)['state']);
        $options->set('QueueRun', $recorded(QueueRun::STARTING, date('Y-m-d H:i:s')));
        self::assertSame(QueueRun::STARTING, self::viewOf($run)['state'], 'just requested');
    }

    /** @return array<string, mixed> */
    private static function viewOf(QueueRun $run): array
    {
        $view = $run->view();
        self::assertNotNull($view, 'a run is recorded');
        return $view;
    }

    private function queued(int $count): string
    {
        $news = $this->createList('NEWS', 'News');
        for ($i = 1; $i <= $count; $i++) {
            $this->setMembership($this->createSubscriber('reader' . $i . '@example.com', 'Reader'), $news, true);
        }
        $muid = $this->createMessage('Campaign', [$news]);
        self::assertSame($count, $this->service(QueueBuilder::class)->queueMessage($muid)->queued);
        return $muid;
    }
}
