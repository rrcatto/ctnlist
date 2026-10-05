<?php

declare(strict_types=1);

namespace App\Tests\Integration\Queue;

use App\Campaign\TemplateRenderer;
use App\Config\SiteConfig;
use App\Log\MessageLog;
use App\Mail\CampaignMailer;
use App\Mail\MailConnectionFactory;
use App\Mail\SmtpServerPool;
use App\Queue\QueueBuilder;
use App\Queue\QueueProcessor;
use App\Repository\MembershipRepository;
use App\Repository\MessageRepository;
use App\Repository\OptionRepository;
use App\Repository\QueueRepository;
use App\Repository\SubscriberRepository;
use App\Suppression\SuppressionChecker;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Clock\ClockInterface;

final class QueueProcessorTest extends IntegrationTestCase
{
    public function testSendsTheQueueThroughTheCampaignPath(): void
    {
        [$muid, $uuids] = $this->queued(3);

        self::assertSame(3, $this->service(QueueProcessor::class)->process());

        self::assertEmailCount(3);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT m_sent FROM messages WHERE m_uniqid = ?', [$muid]));
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sendlog WHERE sl_type = 'MESSAGE' AND sl_muid = ? AND sl_list_shortcode = 'NEWS'", [$muid]));
        foreach ($uuids as $uuid) {
            self::assertTrue($this->service(MessageLog::class)->wasSent($uuid, $muid));
        }
        self::assertSame('N', $this->service(OptionRepository::class)->get(QueueProcessor::CURRENTLY_SENDING));
        self::assertSame(0, $this->service(QueueBuilder::class)->queueMessage($muid)->queued, 'once-only after delivery');
    }

    public function testMaximumSendIsAHardLimitIncludingZero(): void
    {
        [$muid] = $this->queued(3, 2);
        self::assertSame(2, $this->service(QueueProcessor::class)->process());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'), 'the rest stays queued');

        $this->db->executeStatement('UPDATE messages SET m_max_send = 0, m_sent = 0 WHERE m_uniqid = ?', [$muid]);
        self::assertSame(0, $this->service(QueueProcessor::class)->process(), 'zero means none');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'));
    }

    public function testDoesNothingWhileAnotherRunIsSending(): void
    {
        $this->queued(1);
        $this->service(OptionRepository::class)->set(QueueProcessor::CURRENTLY_SENDING, 'Y');

        self::assertSame(0, $this->service(QueueProcessor::class)->process());
        self::assertSame('Y', $this->service(OptionRepository::class)->get(QueueProcessor::CURRENTLY_SENDING), 'the other run keeps its flag');
        self::assertEmailCount(0);
    }

    public function testRechecksEligibilityAndSuppressionBeforeEachSend(): void
    {
        [$muid, , $ids] = $this->queued(2);
        // After queueing, one subscriber unsubscribes; the other's address
        // becomes unusable under the v5 rules (counts as suppressed).
        $this->db->executeStatement('UPDATE list_subscribers SET ls_unsubscribed = TRUE WHERE ls_s_id = ?', [$ids[0]]);
        $this->db->executeStatement("UPDATE subscribers SET s_email = 'x@charity.org' WHERE s_id = ?", [$ids[1]]);

        self::assertSame(0, $this->service(QueueProcessor::class)->process($muid));
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM queue'), 'ineligible rows removed');
        self::assertSame(
            QueueBuilder::SUPPRESSION_REASON,
            $this->db->fetchOne("SELECT ls_unsubscribe_reason FROM list_subscribers ls JOIN lists l ON l.l_id = ls.ls_l_id WHERE ls_s_id = ? AND l.l_shortcode = 'NEWS'", [$ids[1]])
        );
    }

    public function testFailsOverToTheNextServer(): void
    {
        $this->queued(2);
        $site = SiteConfig::fromEnvironment(
            ['MAIL_SMTP_SERVERS_JSON' => '[{"active":1,"dsn":"smtp://127.0.0.1:1","delay":0},{"active":1,"dsn":"null://null","delay":0}]'],
            '/tmp'
        );
        $processor = new QueueProcessor(
            $this->service(QueueRepository::class),
            $this->service(MessageRepository::class),
            $this->service(SubscriberRepository::class),
            $this->service(MembershipRepository::class),
            $this->service(SuppressionChecker::class),
            $this->service(TemplateRenderer::class),
            $this->service(CampaignMailer::class),
            new SmtpServerPool($site, '', 600, 0),
            $this->service(MailConnectionFactory::class),
            $this->service(OptionRepository::class),
            $this->service(ClockInterface::class),
        );

        self::assertSame(2, $processor->process());
        self::assertEmailCount(2);
    }

    /** @return array{0: string, 1: list<string>, 2: list<int>} MUID, subscriber UUIDs and ids */
    private function queued(int $subscribers, int $maxSend = 1000000): array
    {
        $news = $this->createList('NEWS', 'News');
        $uuids = [];
        $ids = [];
        for ($i = 1; $i <= $subscribers; $i++) {
            $ids[] = $id = $this->createSubscriber("reader{$i}@example.com");
            $this->setMembership($id, $news, true);
            $uuids[] = $this->subscriberUuid($id);
        }
        $muid = $this->createMessage('Campaign', [$news], $maxSend);
        self::assertSame($subscribers, $this->service(QueueBuilder::class)->queueMessage($muid)->queued);
        return [$muid, $uuids, $ids];
    }
}
