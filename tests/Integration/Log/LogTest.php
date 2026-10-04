<?php

declare(strict_types=1);

namespace App\Tests\Integration\Log;

use App\Log\MessageActivity;
use App\Log\MessageLog;
use App\Log\SendLog;
use App\Tests\Integration\IntegrationTestCase;

final class LogTest extends IntegrationTestCase
{
    public function testSendLogRecordsAHandoff(): void
    {
        $this->service(SendLog::class)->record('abc', 'MAGIC-LINK', 'jane@example.com', 'all', 'Sign in', ' 01A10309-8535-7BA9-A26A-E7AE433BB2E7 ');
        $row = $this->db->fetchAssociative("SELECT sl_type, sl_list_shortcode, sl_s_uuid, sl_subject FROM sendlog WHERE sl_muid = 'abc'");
        self::assertSame(['sl_type' => 'MAGIC-LINK', 'sl_list_shortcode' => 'ALL', 'sl_s_uuid' => '01a10309-8535-7ba9-a26a-e7ae433bb2e7', 'sl_subject' => 'Sign in'], $row);
    }

    public function testMessageLogIsTheOnceOnlyGuard(): void
    {
        $log = $this->service(MessageLog::class);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com'));
        $muid = $this->createMessage('Hello');

        self::assertFalse($log->hasRecord($uuid, $muid));
        self::assertTrue($log->ensure($uuid, $muid, 'all'));
        self::assertTrue($log->hasRecord($uuid, $muid), 'queued');
        self::assertFalse($log->wasSent($uuid, $muid));
        self::assertTrue($log->ensure(strtoupper($uuid), $muid), 'idempotent, case-insensitive UUID');
        self::assertSame('ALL', $log->listShortcode($uuid, $muid), 'empty list context keeps the recorded one');

        $log->markSent($uuid, $muid, 'news');
        self::assertTrue($log->wasSent($uuid, $muid));
        self::assertSame('NEWS', $log->listShortcode($uuid, $muid));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM smlog WHERE sml_muid = ?', [$muid]));
    }

    public function testRowsOnlyForRealSubscribersAndMessages(): void
    {
        $log = $this->service(MessageLog::class);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com'));
        self::assertFalse($log->ensure($uuid, str_repeat('0', 32)), 'unknown message');
        self::assertFalse($log->ensure('01a10309-8535-7ba9-a26a-e7ae433bb2e7', $this->createMessage('x')), 'unknown subscriber');
        self::assertFalse($log->ensure('not-a-uuid', 'x'));
        $log->record('', 'x', MessageActivity::Booking);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM smlog'));
    }

    public function testActivitiesAlsoCountAsReads(): void
    {
        $log = $this->service(MessageLog::class);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com'));
        $muid = $this->createMessage('Hello');

        $log->record($uuid, $muid, MessageActivity::Read);
        $log->record($uuid, $muid, MessageActivity::Like);
        $log->record($uuid, $muid, MessageActivity::Unsubscribe);
        $log->record($uuid, $muid, MessageActivity::Unsubscribe);

        $row = $this->db->fetchAssociative('SELECT sml_reads, sml_likes, sml_unsubscribe, sml_last_like IS NOT NULL AS liked FROM smlog WHERE sml_muid = ?', [$muid]);
        self::assertSame(['sml_reads' => 4, 'sml_likes' => 1, 'sml_unsubscribe' => 1, 'liked' => true], $row);
    }
}
