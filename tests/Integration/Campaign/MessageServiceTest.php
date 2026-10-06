<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Campaign\ArchiveService;
use App\Campaign\MessageNotFound;
use App\Campaign\MessageService;
use App\Campaign\TemplateRenderer;
use App\CattoMail\SendOutcome;
use App\Config\SiteConfig;
use App\Log\MessageLog;
use App\Repository\ArchiveRepository;
use App\Repository\MessageRepository;
use App\Tests\Integration\IntegrationTestCase;
use Psr\Clock\ClockInterface;

final class MessageServiceTest extends IntegrationTestCase
{
    private const INPUT = ['m_t_id' => 0, 'm_from_name' => '', 'm_from_address' => '', 'm_subject' => '', 'm_priority' => 0, 'm_max_send' => 0, 'm_html' => '', 'm_text' => ''];

    public function testDraftsAreStoredExactlyAsSupplied(): void
    {
        $service = $this->service(MessageService::class);
        $messages = $this->service(MessageRepository::class);

        $muid = $service->save(null, self::INPUT, []);
        $message = $messages->findByMuid($muid);
        self::assertNotNull($message);
        self::assertSame(0, $message['m_max_send'], 'no defaults invented');
        self::assertSame([], $messages->lists($message['m_id']), 'no audience, and ALL is not added');

        $news = $this->createList('NEWS', 'News');
        self::assertSame($muid, $service->save($muid, ['m_subject' => 'Now with lists'] + self::INPUT, [$news, $news, 999999]));
        self::assertSame(['NEWS'], array_column($messages->lists($message['m_id']), 'l_shortcode'), 'duplicates and unknown ids ignored');
        self::assertSame('Now with lists', $messages->findByMuid($muid)['m_subject'] ?? null);

        $this->expectException(MessageNotFound::class);
        $service->save(str_repeat('a', 32), self::INPUT, []);
    }

    public function testFirstQueuePreparationArchivesOnce(): void
    {
        $service = $this->service(MessageService::class);
        $message = $this->service(MessageRepository::class)->findByMuid($this->createMessage('Archived'));
        self::assertNotNull($message);
        $this->db->executeStatement("UPDATE messages SET m_html = '{archive}' WHERE m_id = ?", [$message['m_id']]);
        $message['m_html'] = '{archive}';

        $prepared = $service->prepareForQueue($message);
        self::assertNotNull($prepared['m_datesent']);
        self::assertGreaterThan(0, $prepared['m_a_id']);
        $archive = $this->db->fetchAssociative('SELECT a_subject, a_html FROM archives WHERE a_id = ?', [$prepared['m_a_id']]);
        self::assertSame(['a_subject' => 'Archived', 'a_html' => '<a href="https://ctnlist.test/archive/' . $prepared['m_a_id'] . '">ARCHIVE</a>'], $archive);

        $again = $service->prepareForQueue($prepared);
        self::assertSame($prepared['m_a_id'], $again['m_a_id'], 'archive created only once');
        self::assertSame($prepared['m_datesent'], $again['m_datesent']);
    }

    public function testNoArchiveWhenArchivesAreDisabled(): void
    {
        $site = SiteConfig::fromEnvironment(['APP_ARCHIVE_ENABLED' => 'false'], '/tmp');
        $archives = new ArchiveService($this->service(ArchiveRepository::class), $this->service(TemplateRenderer::class), $site, $this->service(ClockInterface::class));
        $message = $this->service(MessageRepository::class)->findByMuid($this->createMessage('x'));
        self::assertNotNull($message);
        self::assertSame(0, $archives->archive($message));
    }

    public function testDirectSendUsesCattoMailAndListFallbacks(): void
    {
        $fake = $this->fakeCattoMail();
        $service = $this->service(MessageService::class);
        $news = $this->createList('NEWS', 'News');
        $muid = $this->createMessage('Proof', [$news]);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com', 'Jane'));

        self::assertSame(SendOutcome::SUBMITTED, $service->sendTo($muid, 'Jane@Example.com', 'RESEND')->status);
        self::assertEmailCount(0, message: 'not through ctnlist SMTP');
        $job = $fake->sendJobs[$fake->lastSendJobId()];
        self::assertSame(['subscription', 'queued', 'News <news.localhost>'], [$job['message_class'], $job['status'], $job['list_id']]);
        $recipient = $fake->recipients[$fake->lastSendJobId()][0];
        self::assertSame('jane@example.com', $recipient['email_address']);
        self::assertStringStartsWith('<p>Hello Jane</p>', $recipient['html_body']);
        self::assertStringStartsWith('https://ctnlist.test/unsubscribe-link/' . $uuid . '/NEWS/' . $muid . '/', $recipient['unsubscribe_url']);
        self::assertSame('RESEND|NEWS', $this->db->fetchOne("SELECT sl_type || '|' || sl_list_shortcode FROM sendlog ORDER BY sl_id DESC LIMIT 1"), 'first message list');
        self::assertTrue($this->service(MessageLog::class)->wasSent($uuid, $muid));

        $this->db->executeStatement("UPDATE smlog SET sml_list_shortcode = 'ALL' WHERE sml_muid = ?", [$muid]);
        self::assertTrue($service->sendTo($muid, 'jane@example.com')->accepted());
        self::assertSame('RESEND|ALL', $this->db->fetchOne("SELECT sl_type || '|' || sl_list_shortcode FROM sendlog ORDER BY sl_id DESC LIMIT 1"), 'recorded list context wins');

        self::assertFalse($service->sendTo($muid, 'nobody@example.com')->accepted(), 'only to known subscribers');
        self::assertFalse($service->sendTo(str_repeat('b', 32), 'jane@example.com')->accepted());

        $fake->failNext('POST /send-jobs/[0-9a-f-]+/submit', 'timeout', times: 3);
        self::assertSame(SendOutcome::DEFERRED, $service->sendTo($muid, 'jane@example.com')->status, 'kept for the worker');
    }

    /** Proofs go to any valid address and leave subscribers, consent, smlog and the queue alone. */
    public function testProofToAnyAddressCreatesNoSubscriberData(): void
    {
        $fake = $this->fakeCattoMail();
        $service = $this->service(MessageService::class);
        $news = $this->createList('NEWS', 'News');
        $muid = $this->createMessage('Proof', [$news]);
        $this->db->executeStatement('UPDATE messages SET m_html = ?, m_text = ? WHERE m_uniqid = ?', ['<p>Hi {firstname} {unsubscribe} {usertrack}</p>', 'Hi {firstname}', $muid]);
        $counts = fn(): array => array_map('intval', $this->db->fetchAssociative(
            'SELECT (SELECT COUNT(*) FROM subscribers) AS s, (SELECT COUNT(*) FROM list_subscribers) AS ls,
                    (SELECT COUNT(*) FROM list_subscription_events) AS e, (SELECT COUNT(*) FROM smlog) AS sml, (SELECT COUNT(*) FROM queue) AS q'
        ) ?: []);
        $before = $counts();

        self::assertSame(SendOutcome::SUBMITTED, $service->sendProof($muid, 'tester@outside.org')->status, 'any valid address, even one the v5 cleanup rules reject');

        self::assertSame($before, $counts(), 'no subscriber, membership, consent event, smlog row or queue entry');
        self::assertSame(['PROOF', 'NEWS', 'tester@outside.org', null], array_values((array) $this->db->fetchAssociative(
            'SELECT sl_type, sl_list_shortcode, sl_email, sl_s_uuid FROM sendlog ORDER BY sl_id DESC LIMIT 1'
        )), 'logged as a proof without a subscriber');
        $job = $fake->sendJobs[$fake->lastSendJobId()];
        self::assertSame('transactional', $job['message_class'], 'a proof is not subscription mail');
        self::assertArrayNotHasKey('list_id', array_filter($job, static fn($v): bool => $v !== null));
        $recipient = $fake->recipients[$fake->lastSendJobId()][0];
        self::assertArrayNotHasKey('unsubscribe_url', $recipient);
        self::assertStringContainsString('Hi Test', $recipient['html_body'], 'test merge values');
        self::assertStringNotContainsString('/ut/', $recipient['html_body'], 'no open-tracking pixel');
        self::assertSame('Hi Test', $recipient['text_body']);

        foreach (['', 'not-an-address', 'a@'] as $invalid) {
            try {
                $service->sendProof($muid, $invalid);
                self::fail("accepted {$invalid}");
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame($before, $counts());
    }
}
