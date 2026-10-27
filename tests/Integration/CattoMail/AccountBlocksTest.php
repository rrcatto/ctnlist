<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\CattoMailWorker;
use App\Subscriber\Blocking;
use App\Subscriber\SubscriberAdmin;
use App\Suppression\SuppressionChecker;

/**
 * An administrator's blocks (owner decision 2026-10-27): kept by catto-mail as account blocks
 * (POST /v1/blocks, lifted with POST /v1/blocks/{id}/lift), recorded here first with their key,
 * retried by the worker, and stopping the address here too while they are in force.
 */
final class AccountBlocksTest extends CattoMailTestCase
{
    public function testBlockingAnAddressTellsCattoMailAndUnsubscribesFromEveryList(): void
    {
        $news = $this->createList('NEWS', 'News');
        $id = $this->createSubscriber('Ann@Example.COM', 'Ann');
        $this->setMembership($id, $news, true);
        $blocking = $this->service(Blocking::class);
        $checker = $this->service(SuppressionChecker::class);
        self::assertFalse($checker->isSuppressed('Ann@Example.COM'));

        $block = $blocking->blockAddress('Ann@Example.COM', null);
        self::assertSame(['address', 'Ann@example.com', 'active'], [$block['cbl_scope'], $block['cbl_value'], $block['cbl_status']],
            'the domain lower-cased, the local part kept, as catto-mail keeps it');
        $create = $this->fake->requestsTo('POST', '/blocks');
        self::assertCount(1, $create);
        self::assertSame(['address' => 'Ann@example.com', 'external_reference' => $block['cbl_uuid']], $create[0]['body']);
        self::assertSame($block['cbl_idempotency_key'], $create[0]['headers']['idempotency-key']);
        self::assertSame([$block['cbl_remote_id']], array_keys($this->fake->blocks));

        self::assertTrue($checker->isSuppressed('ann@example.com'), 'blocked here too, whatever the case');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND NOT ls_unsubscribed', [$id]));
        self::assertSame(Blocking::UNSUBSCRIBE_REASON, $this->db->fetchOne('SELECT ls_unsubscribe_reason FROM list_subscribers WHERE ls_s_id = ?', [$id]));

        // Blocking it again finds the block: one row, one block at catto-mail.
        self::assertSame($block['cbl_id'], $blocking->blockAddress('ann@EXAMPLE.com', null)['cbl_id']);
        self::assertCount(1, $this->fake->requestsTo('POST', '/blocks'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_blocks'));
    }

    public function testADomainBlockStopsExactlyThatDomain(): void
    {
        $blocking = $this->service(Blocking::class);
        $checker = $this->service(SuppressionChecker::class);
        $block = $blocking->blockDomain('@Spam-Example.COM.', null);
        self::assertSame(['domain', 'spam-example.com', 'active'], [$block['cbl_scope'], $block['cbl_value'], $block['cbl_status']]);
        self::assertSame(['domain' => 'spam-example.com', 'external_reference' => $block['cbl_uuid']], $this->fake->requestsTo('POST', '/blocks')[0]['body']);
        self::assertTrue($checker->isSuppressed('anyone@spam-example.com'));
        self::assertFalse($checker->isSuppressed('anyone@mail.spam-example.com'), 'not its subdomains');
        self::assertSame($block['cbl_uuid'], $blocking->covering('Someone@SPAM-example.com')['domain']['cbl_uuid'] ?? null);
    }

    public function testAnUnreachableMailServiceKeepsTheBlockForTheWorker(): void
    {
        $this->fake->failNext('POST /blocks', 'timeout', times: 3);
        $block = $this->service(Blocking::class)->blockAddress('bob@example.com', null);
        self::assertSame('pending', $block['cbl_status']);
        self::assertTrue($this->service(SuppressionChecker::class)->isSuppressed('bob@example.com'), 'a pending block stops mail here already');
        self::assertSame([], $this->fake->blocks);

        self::assertSame(1, $this->service(CattoMailWorker::class)->run()['blocks reported']);
        self::assertSame('active', $this->db->fetchOne('SELECT cbl_status FROM cattomail_blocks WHERE cbl_id = ?', [$block['cbl_id']]));
        $keys = array_column(array_column($this->fake->requestsTo('POST', '/blocks'), 'headers'), 'idempotency-key');
        self::assertSame([$block['cbl_idempotency_key']], array_values(array_unique($keys)), 'every retry with the stored key');
        self::assertCount(1, $this->fake->blocks);
    }

    public function testUnblockingLiftsAtCattoMailAndReSubscribesNobody(): void
    {
        $news = $this->createList('NEWS', 'News');
        $id = $this->createSubscriber('cat@example.com');
        $this->setMembership($id, $news, true);
        $blocking = $this->service(Blocking::class);
        $block = $blocking->blockAddress('cat@example.com', null);

        $this->fake->clientMayLift = false;
        self::assertSame('active', $blocking->unblock($block['cbl_uuid'], null), 'a refused lift leaves the block in force');
        self::assertStringContainsString('may not lift', (string) $this->db->fetchOne('SELECT cbl_error FROM cattomail_blocks WHERE cbl_id = ?', [$block['cbl_id']]));
        self::assertTrue($this->service(SuppressionChecker::class)->isSuppressed('cat@example.com'));

        $this->fake->clientMayLift = true;
        self::assertSame('lifted', $blocking->unblock($block['cbl_uuid'], null));
        self::assertSame('lifted', $this->fake->blocks[(string) $block['cbl_remote_id']]['status']);
        self::assertFalse($this->service(SuppressionChecker::class)->isSuppressed('cat@example.com'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND NOT ls_unsubscribed', [$id]), 'still unsubscribed');
        self::assertSame(['address' => null, 'domain' => null], $blocking->covering('cat@example.com'));

        // A new block of the same address is a new block.
        self::assertNotSame($block['cbl_id'], $blocking->blockAddress('cat@example.com', null)['cbl_id']);
    }

    public function testBulkUnsubscribeBlocksWhereNoBanlistIsKept(): void
    {
        $admin = $this->service(SubscriberAdmin::class);
        self::assertSame(2, $admin->bulkUnsubscribe("dan@example.com\neve@example.com", 0, 'complained by phone', 'spam'));
        self::assertSame(1, $admin->bulkUnsubscribe('x@bulk-example.com', 0, '', 'domain'));
        self::assertSame(['dan@example.com', 'eve@example.com', 'bulk-example.com'],
            array_values(array_map(static fn(array $b): string => (string) $b['value'], $this->fake->blocks)));
        self::assertTrue($this->service(SuppressionChecker::class)->isSuppressed('y@bulk-example.com'));
    }
}
