<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\CattoMailClient;
use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailWorker;
use App\CattoMail\GlobalOptOut;
use App\Repository\CattoMailOptOutRepository;
use App\Repository\ListRepository;
use App\Repository\MembershipRepository;
use App\Repository\QueueRepository;
use App\Repository\SubscriberRepository;
use App\Security\SubscriberUser;
use App\Subscriber\ConsentService;
use Psr\Clock\ClockInterface;

/**
 * Ordinary unsubscribes stay ctnlist state; only an explicit "no email from
 * any sender" request becomes a catto-mail global opt-out, exactly once.
 */
final class GlobalOptOutTest extends CattoMailTestCase
{
    public function testOrdinaryUnsubscribesAreNeverSentToCattoMail(): void
    {
        $news = $this->createList('NEWS', 'News');
        $id = $this->createSubscriber('ann@example.com', 'Ann');
        $this->setMembership($id, $news, true);
        $list = $this->service(ListRepository::class)->findById($news);
        self::assertNotNull($list);
        $consent = $this->service(ConsentService::class);
        $user = new SubscriberUser($id, $this->subscriberUuid($id), 'ann@example.com', 'Ann', '', ['subscriber'], []);
        $identity = $this->service(SubscriberRepository::class)->findIdentityById($id);
        self::assertNotNull($identity);

        $consent->unsubscribe($user, $list, 'list', 'No longer interested');
        $consent->unsubscribeByLink($identity, $list, '');
        $consent->unsubscribe($user, $list, 'global', 'All lists');

        self::assertSame([], $this->fake->requests, 'list, one-click and all-lists unsubscribes make no catto-mail call');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_global_optouts'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND NOT ls_unsubscribed', [$id]));
    }

    public function testAnExplicitNoContactRequestCreatesOneGlobalOptOut(): void
    {
        $news = $this->createList('NEWS', 'News');
        $id = $this->createSubscriber('Ann@Example.COM');
        $this->setMembership($id, $news, true);

        $optOut = $this->service(GlobalOptOut::class)->request($id, $this->subscriberUuid($id), 'Ann@Example.COM');

        self::assertSame('active', $optOut['cgo_status']);
        self::assertCount(1, $this->fake->optOuts);
        $remote = $this->fake->optOuts[(string) $optOut['cgo_remote_id']];
        self::assertSame(['Ann@example.com', $optOut['cgo_uuid'], 'recipient_global_opt_out'], [$remote['email_address'], $remote['external_reference'], $remote['reason']]);
        self::assertSame($optOut['cgo_idempotency_key'], $this->fake->requestsTo('POST', '/global-suppressions')[0]['headers']['idempotency-key']);
        self::assertSame(GlobalOptOut::UNSUBSCRIBE_REASON, $this->db->fetchOne('SELECT ls_unsubscribe_reason FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$id, $news]),
            'they want no email at all, so ctnlist unsubscribes them too');

        // Asking again changes nothing.
        $this->service(GlobalOptOut::class)->request($id, $this->subscriberUuid($id), 'Ann@Example.COM');
        self::assertCount(1, $this->fake->optOuts);
        self::assertCount(1, $this->fake->requestsTo('POST', '/global-suppressions'));
    }

    public function testRetriesNeverCreateADuplicateOptOut(): void
    {
        $id = $this->createSubscriber('ben@example.com');
        $this->fake->failNext('POST /global-suppressions', 'timeout', afterEffect: true, times: 3);

        $optOut = $this->service(GlobalOptOut::class)->request($id, $this->subscriberUuid($id), 'ben@example.com');
        self::assertSame('pending', $optOut['cgo_status'], 'kept for the worker');
        self::assertCount(1, $this->fake->optOuts);

        self::assertSame(1, $this->service(CattoMailWorker::class)->run()['opt-outs reported']);
        self::assertCount(1, $this->fake->optOuts, 'the stored key replays');
        self::assertSame('active', $this->service(CattoMailOptOutRepository::class)->find($optOut['cgo_id'])['cgo_status'] ?? '');
        self::assertCount(1, array_unique(array_map(static fn(array $r): string => $r['headers']['idempotency-key'], $this->fake->requestsTo('POST', '/global-suppressions'))));
    }

    public function testWithdrawalLiftsOnlyThatOptOut(): void
    {
        $id = $this->createSubscriber('cas@example.com');
        $optOut = $this->service(GlobalOptOut::class)->request($id, $this->subscriberUuid($id), 'cas@example.com');
        $this->service(GlobalOptOut::class)->withdraw($id);

        self::assertSame('lifted', $this->fake->optOuts[(string) $optOut['cgo_remote_id']]['status']);
        self::assertSame('lifted', $this->service(CattoMailOptOutRepository::class)->find($optOut['cgo_id'])['cgo_status'] ?? '');
        self::assertNull($this->service(GlobalOptOut::class)->current($id));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_s_id = ? AND NOT ls_unsubscribed', [$id]), 'withdrawing does not re-subscribe');
    }

    public function testWithoutTheCapabilityNothingIsReported(): void
    {
        $id = $this->createSubscriber('dee@example.com');
        $this->fake->optOutCapability = false;
        $optOut = $this->service(GlobalOptOut::class)->request($id, $this->subscriberUuid($id), 'dee@example.com');
        self::assertSame('rejected', $optOut['cgo_status']);
        self::assertSame([], $this->fake->optOuts);

        $disabled = new GlobalOptOut(
            $this->service(CattoMailOptOutRepository::class),
            $this->service(CattoMailClient::class),
            new CattoMailConfig('https://cattomail.test', 'key', 'secret', '', globalOptOutEnabled: false),
            $this->service(MembershipRepository::class),
            $this->service(QueueRepository::class),
            $this->service(ClockInterface::class),
        );
        self::assertFalse($disabled->isAvailable());
        $this->expectException(\InvalidArgumentException::class);
        $disabled->request($id, $this->subscriberUuid($id), 'dee@example.com');
    }
}
