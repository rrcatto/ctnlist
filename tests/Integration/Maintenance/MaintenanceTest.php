<?php

declare(strict_types=1);

namespace App\Tests\Integration\Maintenance;

use App\Command\MaintenanceCommand;
use App\Maintenance\Maintenance;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** ctnlist:maintenance: what it removes, what it must never touch, dry run, batches, locking. */
final class MaintenanceTest extends IntegrationTestCase
{
    public function testWebhookBodiesArePrunedOnlyWhenProcessedAndOld(): void
    {
        $job = '0199c000-0000-7000-8000-000000000001';
        $old = $this->event('send.completed', '{"data":{"id":"' . $job . '"}}', processedDaysAgo: 120);
        $recent = $this->event('send.completed', '{"data":{"id":"' . $job . '"}}', processedDaysAgo: 10);
        $unprocessed = $this->event('message.hard_bounced', '{"data":{"message":{"send_job_id":"' . $job . '"}}}', processedDaysAgo: null, receivedDaysAgo: 200);

        $report = $this->service(Maintenance::class)->run();
        self::assertSame(1, $report['webhook bodies pruned']);

        $row = $this->db->fetchAssociative('SELECT cwe_type, cwe_payload, cwe_related_id, cwe_outcome, cwe_processed_at, cwe_received_at, cwe_payload_pruned_at FROM cattomail_webhook_events WHERE cwe_id = ?', [$old]);
        self::assertIsArray($row);
        self::assertNull($row['cwe_payload'], 'the raw body is gone');
        self::assertSame(['send.completed', $job, 'fixture outcome'], [$row['cwe_type'], $row['cwe_related_id'], $row['cwe_outcome']], 'type, related job and outcome stay');
        self::assertNotNull($row['cwe_processed_at']);
        self::assertNotNull($row['cwe_received_at']);
        self::assertNotNull($row['cwe_payload_pruned_at']);
        self::assertNotNull($this->db->fetchOne('SELECT cwe_payload FROM cattomail_webhook_events WHERE cwe_id = ?', [$recent]), 'within the retention period');
        self::assertNotNull($this->db->fetchOne('SELECT cwe_payload FROM cattomail_webhook_events WHERE cwe_id = ?', [$unprocessed]), 'never before it is processed, however old');
    }

    public function testDryRunCountsAndChangesNothing(): void
    {
        $this->event('send.completed', '{"data":{}}', processedDaysAgo: 120);
        $id = $this->createSubscriber('ann@example.com');
        $this->token($id, expiresAgo: 7200, createdAgo: 10000);
        $before = $this->fingerprint();

        $report = $this->service(Maintenance::class)->run(dryRun: true);
        self::assertSame(1, $report['webhook bodies pruned']);
        self::assertSame(1, $report['expired sign-in links removed']);
        self::assertSame($before, $this->fingerprint(), 'nothing changed');
    }

    public function testSignInDataIsRemovedOnlyWhenItCanNoLongerBeUsed(): void
    {
        $id = $this->createSubscriber('ann@example.com');
        $expired = $this->token($id, expiresAgo: 7200, createdAgo: 10000);
        $used = $this->token($id, expiresAgo: -600, createdAgo: 10000, used: true);
        $rateWindow = $this->token($id, expiresAgo: 60, createdAgo: 1900, used: true);
        $valid = $this->token($id, expiresAgo: -1200, createdAgo: 600);
        $active = $this->session($id, expiresIn: 3600);
        $ended = $this->session($id, expiresIn: -60);
        $signedOut = $this->session($id, expiresIn: 3600, revoked: true);
        $this->db->executeStatement("INSERT INTO sessions (ses_id, ses_data, ses_stamp) VALUES ('old-session', '', ?), ('fresh-session', '', ?)", [time() - 100000, time()]);

        $report = $this->service(Maintenance::class)->run();

        self::assertSame(2, $report['expired sign-in links removed']);
        self::assertSame([$rateWindow, $valid], array_map('intval', $this->db->fetchFirstColumn('SELECT alt_id FROM auth_login_tokens WHERE alt_id IN (?, ?, ?, ?) ORDER BY alt_id', [$expired, $used, $rateWindow, $valid])),
            'still valid, or still counted by the sign-in rate limits');
        self::assertSame([$active], array_map('intval', $this->db->fetchFirstColumn('SELECT as_id FROM auth_sessions WHERE as_id IN (?, ?, ?)', [$active, $ended, $signedOut])), 'the active session stays');
        self::assertSame(['fresh-session'], $this->db->fetchFirstColumn("SELECT ses_id FROM sessions WHERE ses_id IN ('old-session', 'fresh-session')"));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscribers WHERE s_id = ?', [$id]), 'identity untouched');
    }

    /** Retry material stays while the work can still be retried; only refused, old jobs lose their rendered content. */
    public function testRetryPayloadsStayWhilePendingAndConsentIsNeverTouched(): void
    {
        $id = $this->createSubscriber('ann@example.com');
        $events = (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscription_events');
        $pending = $this->sendJob('ready', completedDaysAgo: null, createdDaysAgo: 400, recipientStatus: 'staged');
        $refusedOld = $this->sendJob('failed', completedDaysAgo: 400, createdDaysAgo: 400, recipientStatus: 'not_sent');
        $refusedRecent = $this->sendJob('failed', completedDaysAgo: 5, createdDaysAgo: 5, recipientStatus: 'not_sent');

        $report = $this->service(Maintenance::class)->run();

        self::assertSame(1, $report['refused-job content cleared']);
        $content = fn(int $job): ?string => ($v = $this->db->fetchOne('SELECT crp_html FROM cattomail_recipients WHERE crp_csj_id = ?', [$job])) === false ? null : $v;
        self::assertSame('<p>x</p>', $content($pending), 'a job that can still be sent keeps its exact body');
        self::assertNotNull($this->db->fetchOne('SELECT csj_request FROM cattomail_send_jobs WHERE csj_id = ?', [$pending]), 'and its create body and key');
        self::assertNull($content($refusedOld));
        self::assertSame('<p>x</p>', $content($refusedRecent));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_recipients WHERE crp_csj_id IN (?, ?, ?)', [$pending, $refusedOld, $refusedRecent]), 'delivery rows are history: kept');
        self::assertSame($events, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscription_events'), 'consent history untouched');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscribers WHERE s_id = ?', [$id]));
    }

    public function testOnlySupersededValidationResultsAreRemoved(): void
    {
        $uuid = $this->subscriberUuid($this->createSubscriber('ann@example.com'));
        $other = $this->subscriberUuid($this->createSubscriber('ben@example.com'));
        $old = $this->validationJob(completedDaysAgo: 500, results: [$uuid => 'risky', $other => 'deliverable']);
        $new = $this->validationJob(completedDaysAgo: 2, results: [$uuid => 'deliverable']);

        self::assertSame(1, $this->service(Maintenance::class)->run()['superseded validation results removed']);
        self::assertSame([$other], $this->db->fetchFirstColumn('SELECT cva_s_uuid FROM cattomail_validation_addresses WHERE cva_cvj_id = ?', [$old]), "ben's only result stays");
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_validation_addresses WHERE cva_cvj_id = ?', [$new]), "ann's latest result stays");
        self::assertNotNull($this->db->fetchOne('SELECT cvj_detail_pruned_at FROM cattomail_validation_jobs WHERE cvj_id = ?', [$old]), 'the job says its detail was pruned');
        self::assertNotNull($this->db->fetchOne('SELECT cvj_counts FROM cattomail_validation_jobs WHERE cvj_id = ?', [$old]), 'its counts stay');
    }

    /** Cleanup works in bounded batches: the per-run cap leaves the rest for the next run. */
    public function testCleanupIsBatchedAndBounded(): void
    {
        $this->db->executeStatement(
            "INSERT INTO cattomail_webhook_events (cwe_event_id, cwe_type, cwe_payload, cwe_received_at, cwe_processed_at, cwe_outcome)
             SELECT ('0199c111-0000-7000-8000-' || lpad(to_hex(n), 12, '0'))::uuid, 'send.completed', '{}', ?, ?, 'ok' FROM generate_series(1, 25) n",
            [$this->daysAgo(200), $this->daysAgo(200)]
        );
        $maintenance = $this->service(Maintenance::class);
        $maintenance->useBatches(10, 2);
        self::assertSame(25, $maintenance->run(dryRun: true)['webhook bodies pruned']);
        self::assertSame(20, $maintenance->run()['webhook bodies pruned'], 'two batches of ten, then this run stops');
        self::assertSame(5, $maintenance->run()['webhook bodies pruned'], 'the rest on the next run');
        self::assertSame(0, $maintenance->run()['webhook bodies pruned']);
    }

    /** The Site Log is kept unless APP_SITELOG_RETENTION_DAYS is set (it holds IP addresses and is not needed forever). */
    public function testSiteLogIsPrunedOnlyWhenConfigured(): void
    {
        $this->db->executeStatement("INSERT INTO sitelog (stl_url, stl_logged_at) VALUES ('/old', ?), ('/new', ?)", [$this->daysAgo(400), $this->daysAgo(1)]);
        self::assertArrayNotHasKey('Site Log rows removed', $this->service(Maintenance::class)->run(), 'kept by default');
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sitelog WHERE stl_url = '/old'"));

        $withRetention = new Maintenance($this->db, $this->service(\App\Repository\CattoMailWebhookRepository::class), $this->service(\App\CattoMail\CattoMailConfig::class),
            $this->service(\App\Config\RuntimeSettings::class), $this->service(\App\Maintenance\StuckWork::class), $this->service(\App\Maintenance\DatabaseLock::class),
            $this->service(\App\Maintenance\MaintenanceActivity::class), $this->service(\Psr\Clock\ClockInterface::class), new \Psr\Log\NullLogger(), '365');
        self::assertGreaterThanOrEqual(1, $withRetention->run()['Site Log rows removed']);
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sitelog WHERE stl_url = '/old'"));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sitelog WHERE stl_url = '/new'"));
    }

    public function testOverlappingRunsSkip(): void
    {
        $other = DriverManager::getConnection($this->db->getParams());
        self::assertTrue((bool) $other->fetchOne("SELECT pg_try_advisory_lock(hashtext('ctnlist:maintenance'))"));
        try {
            self::assertSame(['skipped (maintenance is already running)' => 1], $this->service(Maintenance::class)->run());
        } finally {
            $other->fetchOne("SELECT pg_advisory_unlock(hashtext('ctnlist:maintenance'))");
            $other->close();
        }
    }

    public function testCommandOutputAndExitStatus(): void
    {
        $command = new CommandTester($this->service(MaintenanceCommand::class));
        self::assertSame(Command::SUCCESS, $command->execute([]));
        $this->event('send.completed', '{"data":{}}', processedDaysAgo: 120);
        self::assertSame(Command::SUCCESS, $command->execute(['--dry-run' => true]));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d maintenance \(dry run, nothing changed\): .*webhook bodies pruned 1.*failures 0/', $command->getDisplay());
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('webhook bodies pruned 1', $command->getDisplay());
        self::assertStringNotContainsString('{"data"', $command->getDisplay(), 'never row content');
    }

    private function event(string $type, string $payload, ?int $processedDaysAgo, int $receivedDaysAgo = 200): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO cattomail_webhook_events (cwe_event_id, cwe_type, cwe_payload, cwe_received_at, cwe_processed_at, cwe_outcome)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING cwe_id',
            [sprintf('0199c222-0000-7000-8000-%012x', random_int(1, 0xFFFFFFFFFFFF)), $type, $payload, $this->daysAgo($receivedDaysAgo),
                $processedDaysAgo === null ? null : $this->daysAgo($processedDaysAgo), $processedDaysAgo === null ? null : 'fixture outcome']
        );
    }

    private function token(int $subscriberId, int $expiresAgo, int $createdAgo, bool $used = false): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO auth_login_tokens (alt_s_id, alt_email, alt_token_hash, alt_created_at, alt_expires_at, alt_used_at) VALUES (?, 'ann@example.com', ?, ?, ?, ?) RETURNING alt_id",
            [$subscriberId, hash('sha256', random_bytes(16)), date('Y-m-d H:i:s', time() - $createdAgo), date('Y-m-d H:i:s', time() - $expiresAgo),
                $used ? date('Y-m-d H:i:s', time() - $createdAgo + 30) : null]
        );
    }

    private function session(int $subscriberId, int $expiresIn, bool $revoked = false): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO auth_sessions (as_s_id, as_token_hash, as_expires_at, as_revoked_at) VALUES (?, ?, ?, ?) RETURNING as_id',
            [$subscriberId, hash('sha256', random_bytes(16)), date('Y-m-d H:i:s', time() + $expiresIn), $revoked ? date('Y-m-d H:i:s') : null]
        );
    }

    private function sendJob(string $status, ?int $completedDaysAgo, int $createdDaysAgo, string $recipientStatus): int
    {
        $run = (int) $this->db->fetchOne("INSERT INTO cattomail_runs (cr_kind) VALUES ('campaign') RETURNING cr_id");
        $job = (int) $this->db->fetchOne(
            "INSERT INTO cattomail_send_jobs (csj_cr_id, csj_seq, csj_idempotency_key, csj_message_class, csj_request, csj_status, csj_created_at, csj_completed_at)
             VALUES (?, 1, ?, 'transactional', '{\"x\":1}', ?, ?, ?) RETURNING csj_id",
            [$run, bin2hex(random_bytes(16)), $status, $this->daysAgo($createdDaysAgo), $completedDaysAgo === null ? null : $this->daysAgo($completedDaysAgo)]
        );
        $batch = (int) $this->db->fetchOne('INSERT INTO cattomail_batches (cb_csj_id, cb_seq, cb_idempotency_key) VALUES (?, 1, ?) RETURNING cb_id', [$job, bin2hex(random_bytes(16))]);
        $this->db->executeStatement(
            "INSERT INTO cattomail_recipients (crp_csj_id, crp_cb_id, crp_email, crp_type, crp_subject, crp_html, crp_text, crp_status) VALUES (?, ?, 'ann@example.com', 'MESSAGE', 'S', '<p>x</p>', 'x', ?)",
            [$job, $batch, $recipientStatus]
        );
        return $job;
    }

    /** @param array<string, string> $results subscriber UUID => classification */
    private function validationJob(int $completedDaysAgo, array $results): int
    {
        $job = (int) $this->db->fetchOne(
            "INSERT INTO cattomail_validation_jobs (cvj_idempotency_key, cvj_status, cvj_total, cvj_counts, cvj_results_complete, cvj_completed_at)
             VALUES (?, 'completed', ?, '{}', TRUE, ?) RETURNING cvj_id",
            [bin2hex(random_bytes(16)), count($results), $this->daysAgo($completedDaysAgo)]
        );
        foreach ($results as $uuid => $class) {
            $this->db->executeStatement("INSERT INTO cattomail_validation_addresses (cva_cvj_id, cva_s_uuid, cva_address, cva_classification, cva_checked_at) VALUES (?, ?, 'a@example.com', ?, ?)",
                [$job, $uuid, $class, $this->daysAgo($completedDaysAgo)]);
        }
        return $job;
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400);
    }

    private function fingerprint(): string
    {
        return (string) json_encode($this->db->fetchAssociative(
            "SELECT (SELECT COUNT(*) FROM auth_login_tokens) AS t, (SELECT COUNT(*) FROM auth_sessions) AS s, (SELECT COUNT(*) FROM sessions) AS p,
                    (SELECT COUNT(*) FROM cattomail_webhook_events WHERE cwe_payload IS NULL) AS w, (SELECT COUNT(*) FROM cattomail_validation_addresses) AS v,
                    (SELECT COUNT(*) FROM cattomail_recipients WHERE crp_html IS NULL) AS r, (SELECT COUNT(*) FROM options WHERE o_key LIKE 'maintenance:%') AS m"
        ));
    }
}
