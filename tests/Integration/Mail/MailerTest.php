<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mail;

use App\Config\SiteConfig;
use App\Log\MessageLog;
use App\Log\SendLog;
use App\Mail\CampaignDelivery;
use App\Mail\CampaignMailer;
use App\Mail\MailConnectionFactory;
use App\Mail\SmtpServer;
use App\Mail\SmtpServerPool;
use App\Mail\TransactionalMailer;
use App\Repository\SubscriberRepository;
use App\Subscriber\Engagement;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Mime\Email;

final class MailerTest extends IntegrationTestCase
{
    public function testNotificationIsBlindCopiedAndLogged(): void
    {
        $sent = $this->service(TransactionalMailer::class)->sendNotification(
            'm1', 'UNSUBSCRIBE', '', 'jane@example.com', 'Jane', 'Bye', '<p>Bye</p>', 'Bye', null, 'news'
        );
        self::assertTrue($sent);
        self::assertEmailCount(1);
        $email = $this->email();
        self::assertSame('jane@example.com', $email->getTo()[0]->getAddress());
        self::assertNotEmpty($email->getBcc(), 'administrator copy');
        self::assertSame(['UNSUBSCRIBE', 'NEWS'], $this->lastSendLog());
    }

    public function testMagicLinkAndInvitation(): void
    {
        $mailer = $this->service(TransactionalMailer::class);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com'));

        self::assertTrue($mailer->sendMagicLink('jane@example.com', $uuid, 'https://x/auth/verify?token=t', 1800));
        self::assertStringContainsString('expires in 30 minutes', (string) $this->email()->getTextBody());
        self::assertSame(['MAGIC-LINK', ''], $this->lastSendLog());

        self::assertTrue($mailer->sendListConfirmationInvitation('jane@example.com', $uuid, 'news', 'News'));
        self::assertStringContainsString('/confirm/' . $uuid . '/NEWS', (string) $this->email(1)->getTextBody());
        self::assertSame(['SUBSCRIBE', 'NEWS'], $this->lastSendLog());
    }

    public function testContactAcknowledgementRecordsTheBooking(): void
    {
        $subscriberId = $this->createSubscriber('jane@example.com');
        $uuid = $this->subscriberUuid($subscriberId);
        $muid = $this->createMessage('Offer');

        $recipients = $this->service(TransactionalMailer::class)->sendContactAcknowledgement('Jane@example.com', 'Jane', '<p>Thanks</p>', 'Thanks', '', $muid);

        self::assertGreaterThanOrEqual(2, $recipients, 'submitter plus internal copies');
        self::assertSame(['CONTACT', ''], $this->lastSendLog());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_bookings FROM smlog WHERE sml_s_uuid = ? AND sml_muid = ?', [$uuid, $muid]), 'subscriber found by address');
        self::assertSame(100000, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$subscriberId]));
    }

    public function testCampaignHeadersAndLogs(): void
    {
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com'));
        $muid = $this->createMessage('Campaign');
        $connection = $this->service(MailConnectionFactory::class)->create();
        self::assertTrue($connection->open(new SmtpServer('null://null')));
        $mailer = $this->service(CampaignMailer::class);

        self::assertTrue($mailer->send($connection, $this->delivery($uuid, $muid, 'news')));
        $headers = $this->email()->getHeaders();
        self::assertSame('<http://localhost:8180/unsubscribe/' . $uuid . '/NEWS/' . $muid . '>', $headers->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame($muid, $headers->get('X-ctnlist-muid')?->getBodyAsString());
        self::assertSame('info+' . $uuid . '@localhost', $this->email()->getSender()?->getAddress());
        self::assertSame(['MESSAGE', 'NEWS'], $this->lastSendLog());
        self::assertTrue($this->service(MessageLog::class)->wasSent($uuid, $muid));

        self::assertTrue($mailer->send($connection, $this->delivery($uuid, $muid, ''), 'proof'));
        self::assertFalse($this->email(1)->getHeaders()->has('List-Unsubscribe'), 'no list context, no unsubscribe link');
        self::assertSame(['PROOF', ''], $this->lastSendLog());
    }

    public function testFailedDeliveryIsNotLogged(): void
    {
        $site = $this->service(SiteConfig::class);
        $mailer = new TransactionalMailer(
            new SmtpServerPool($site, 'smtp://127.0.0.1:1', 600, 0),
            $this->service(MailConnectionFactory::class),
            $this->service(SendLog::class),
            $this->service(MessageLog::class),
            $this->service(SubscriberRepository::class),
            $this->service(Engagement::class),
            $site,
        );
        self::assertFalse($mailer->sendMagicLink('jane@example.com', '', 'https://x', 60));
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM sendlog'));
    }

    private function delivery(string $uuid, string $muid, string $list): CampaignDelivery
    {
        return new CampaignDelivery($muid, 'Campaign', 'Sender', 'sender@ctnlist.test', $uuid, 'jane@example.com', 'Jane', $list, '<p>Hi</p>', 'Hi');
    }

    private function email(int $index = 0): Email
    {
        $message = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $message);
        return $message;
    }

    /** @return array{string, string} type and list of the newest Send Log row */
    private function lastSendLog(): array
    {
        $row = $this->db->fetchAssociative('SELECT sl_type, sl_list_shortcode FROM sendlog ORDER BY sl_id DESC LIMIT 1');
        self::assertIsArray($row);
        return [(string) $row['sl_type'], (string) $row['sl_list_shortcode']];
    }
}
