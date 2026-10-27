<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriber;

use App\Log\MessageLog;
use App\Repository\SubscriberRepository;
use App\Subscriber\SubscriberAdmin;
use App\Tests\Integration\IntegrationTestCase;
use App\Validator\InvalidField;

final class SubscriberAdminTest extends IntegrationTestCase
{
    public function testBulkSubscribeAndUnsubscribe(): void
    {
        $admin = $this->service(SubscriberAdmin::class);
        $news = $this->createList('NEWS', 'News');

        self::assertSame(2, $admin->bulkSubscribe("a@example.com, b@example.com\nnot-an-address joe@charity.org a@example.com", 7, $news));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_l_id = ? AND NOT ls_confirmed', [$news]), 'pending, not confirmed');
        self::assertEmailCount(4, message: 'ALL and NEWS invitations for two new identities');
        self::assertStringContainsString('Number subscribed: 2', (string) file_get_contents(getenv('APP_LOG_DIR') . '/emails_added.txt'));

        $this->db->executeStatement('UPDATE list_subscribers SET ls_confirmed = TRUE WHERE ls_l_id = ?', [$news]);
        self::assertSame(1, $admin->bulkUnsubscribe('a@example.com unknown@example.com', $news, 'Bulk unsubscribe', 'list'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM list_subscribers WHERE ls_l_id = ? AND ls_unsubscribed', [$news]));
        foreach (['domain', 'bounce', 'spam'] as $scope) {
            try {
                $admin->bulkUnsubscribe('a@example.com', $news, '', $scope);
                self::fail($scope . ': nothing can be recorded without a suppression database, and the administrator is told so');
            } catch (InvalidField $e) {
                self::assertSame('scope', $e->field);
                self::assertStringContainsString('keeps no global suppression list', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $admin->bulkUnsubscribe('a@example.com', 999999, '', 'list');
    }

    public function testSaveSubscriberAsAdministrator(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $uuid = $this->subscriberUuid($id);
        $news = $this->createList('NEWS', 'News');
        $muid = $this->createMessage('Offer');

        $message = $this->service(SubscriberAdmin::class)->save($uuid, ['s_fname' => ' Jane ', 's_country' => 'Zambia', 's_priority' => '50'], $muid, true);

        self::assertSame('Subscriber saved.', $message);
        $row = $this->db->fetchAssociative('SELECT s_fname, s_country, s_priority FROM subscribers WHERE s_id = ?', [$id]);
        self::assertSame(['s_fname' => 'Jane', 's_country' => 'Zambia', 's_priority' => 150], $row, 'v5: saving adds 100 to the priority');
        self::assertSame(['UPDATE-PROFILE'], array_map('strval', $this->db->fetchFirstColumn('SELECT sl_type FROM sendlog ORDER BY sl_id')));
        self::assertTrue($this->service(MessageLog::class)->hasRecord($uuid, $muid), 'smlog update recorded');

        // Inviting to a list is its own action (the subscriber page's Invite): a pending membership and the invitation.
        self::assertSame('Invited jane@example.com to News: the membership is pending until they confirm it.', $this->service(SubscriberAdmin::class)->invite($uuid, $news));
        self::assertSame([false], array_map('boolval', $this->db->fetchFirstColumn('SELECT ls_confirmed FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$id, $news])));
        self::assertSame(['UPDATE-PROFILE', 'SUBSCRIBE'], array_map('strval', $this->db->fetchFirstColumn('SELECT sl_type FROM sendlog ORDER BY sl_id')));
        self::assertSame('jane@example.com already has a membership of News, so no invitation was sent.', $this->service(SubscriberAdmin::class)->invite($uuid, $news));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM sendlog'), 'not invited twice');
        try {
            $this->service(SubscriberAdmin::class)->invite($uuid, 999999);
            self::fail('an unknown list is refused');
        } catch (InvalidField $e) {
            self::assertSame('listId', $e->field);
        }

        $this->service(SubscriberAdmin::class)->save($uuid, ['s_priority' => '999'], '', false);
        self::assertSame(100, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]), 'subscribers cannot set their priority');
    }

    public function testReportListsMembershipsAsStructuredData(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $this->setMembership($id, $this->createList('NEWS', 'News, views: daily'), true);
        $this->setMembership($id, $this->createList('OLD', 'Old'), false, true);

        $rows = $this->service(SubscriberRepository::class)->reportPage('jane@example.com', false, '', 0, 0, 10);

        self::assertCount(1, $rows);
        self::assertSame([
            ['name' => 'ALL', 'state' => 'pending'],
            ['name' => 'News, views: daily', 'state' => 'confirmed'],
            ['name' => 'Old', 'state' => 'unsubscribed'],
        ], $rows[0]['memberships'], 'names exactly as stored, states separately');
    }

    public function testExportWritesBothFiles(): void
    {
        $news = $this->createList('NEWS', 'News');
        $this->setMembership($this->createSubscriber('in@example.com'), $news, true);
        $this->setMembership($this->createSubscriber('out@example.com'), $news, false, true);

        $report = $this->service(SubscriberAdmin::class)->export(0, 100, $news);

        self::assertSame(['export-subscribers.txt - export complete (1 addresses).', 'export-remove.txt - export complete (1 addresses).'], $report);
        self::assertSame("in@example.com\n", file_get_contents(getenv('APP_LOG_DIR') . '/export-subscribers.txt'));
        self::assertSame("out@example.com\n", file_get_contents(getenv('APP_LOG_DIR') . '/export-remove.txt'));
    }
}
