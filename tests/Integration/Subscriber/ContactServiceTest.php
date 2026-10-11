<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriber;

use App\Config\SiteConfig;
use App\Log\MessageLog;
use App\Log\SendLog;
use App\Mail\MailConnectionFactory;
use App\Mail\SmtpServerPool;
use App\Mail\TransactionalMailer;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Subscriber\ContactService;
use App\Subscriber\Engagement;
use App\Tests\Integration\IntegrationTestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class ContactServiceTest extends IntegrationTestCase
{
    private const CONTEXT = ['subscriber_uuid' => '', 'muid' => '', 'ip' => '10.0.0.1', 'user_agent' => 'test-agent', 'forwarded_for' => ''];

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = $this->service(SiteConfig::class)->contactLogFile;
        @unlink($this->log);
    }

    /**
     * A contact link travels with a forwarded message, so whoever submits the
     * form is told nothing about the subscriber it belongs to: that is in the
     * organisation's copy and the log only.
     */
    public function testTheOrganisationIsToldWhoseLinkItWasAndTheSubmitterIsNot(): void
    {
        $site = $this->service(SiteConfig::class);
        $id = $this->createSubscriber('jane@example.com');
        $uuid = $this->subscriberUuid($id);
        $muid = $this->createMessage('Spring offer');

        $message = $this->service(ContactService::class)->submit(
            ['name' => 'Visitor', 'email' => 'visitor@example.com', 'topic' => 'Booking', 'message' => '<script>alert(1)</script>'],
            ['subscriber_uuid' => $uuid, 'muid' => $muid] + self::CONTEXT
        );

        self::assertStringStartsWith('Thank you for your submission!', $message);
        self::assertEmailCount(2);
        $notice = $this->email(0);
        $internal = array_values(array_unique([strtolower($site->contactEmail), strtolower($site->adminEmail)]));
        self::assertSame($internal, self::addresses($notice->getTo()), 'the contact address and the administrator');
        self::assertSame(['visitor@example.com'], self::addresses($notice->getReplyTo()), 'a reply goes to the person who wrote');
        self::assertSame('Contact form: visitor@example.com', $notice->getSubject());
        $text = (string) $notice->getTextBody();
        foreach (["Topic:\nBooking", "SubscriberEmail:\njane@example.com", "SubscriberUUID:\n{$uuid}", "MessageSubject:\nSpring offer", "MessageMUID:\n{$muid}", "IPAddr:\n10.0.0.1", "UserAgent:\ntest-agent"] as $line) {
            self::assertStringContainsString($line, $text);
        }
        self::assertStringNotContainsString('Booking-Form-URL', $text);
        self::assertStringNotContainsString('<script>', (string) $notice->getHtmlBody(), 'submitted values are escaped');

        $acknowledgement = $this->email(1);
        self::assertSame(['visitor@example.com'], self::addresses([...$acknowledgement->getTo(), ...$acknowledgement->getCc(), ...$acknowledgement->getBcc()]), 'nobody else');
        $sent = $acknowledgement->toString();
        foreach (['jane@example.com', $uuid, $muid, 'Spring offer', '10.0.0.1', 'test-agent', 'Subscriber', 'IPAddr'] as $private) {
            self::assertStringNotContainsString($private, $sent, "the acknowledgement does not carry {$private}");
        }
        self::assertStringContainsString("Comments:\n<script>alert(1)</script>", (string) $acknowledgement->getTextBody(), 'what they wrote');
        self::assertStringNotContainsString('<script>', (string) $acknowledgement->getHtmlBody());

        $written = (string) file_get_contents($this->log);
        self::assertStringContainsString("Comments:\n<script>alert(1)</script>", $written);
        self::assertStringContainsString("SubscriberEmail:\njane@example.com", $written);
        self::assertStringContainsString("MessageSubject:\nSpring offer", $written);
        self::assertStringEndsWith("EMAIL-OK\n", $written);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_bookings FROM smlog WHERE sml_s_uuid = ? AND sml_muid = ?', [$uuid, $muid]), 'counted for the link\'s subscriber and message');
        self::assertSame(100000, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]));
        self::assertSame(count($internal) + 1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM sendlog WHERE sl_type = 'CONTACT' AND sl_muid = ? AND sl_s_uuid = ?", [$muid, $uuid]), 'every handoff is in the Send Log');
    }

    public function testWithoutALinkTheSubscriberIsFoundByTheirAddress(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $muid = $this->createMessage('Offer');

        $this->service(ContactService::class)->submit(['name' => 'Jane', 'email' => 'Jane@example.com', 'message' => 'Hello'], ['muid' => $muid] + self::CONTEXT);

        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_bookings FROM smlog WHERE sml_s_uuid = ? AND sml_muid = ?', [$this->subscriberUuid($id), $muid]));
        self::assertSame(100000, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]));
        self::assertStringContainsString("SubscriberUUID:\n\n", (string) $this->email(0)->getTextBody(), 'no link was used');
    }

    /** When the organisation's copy cannot be sent, the submitter is told so, not thanked, and nothing is counted. */
    public function testNothingIsAcknowledgedOrCountedWhenTheOrganisationIsNotReached(): void
    {
        $site = $this->service(SiteConfig::class);
        $id = $this->createSubscriber('jane@example.com');
        $uuid = $this->subscriberUuid($id);
        $muid = $this->createMessage('Offer');
        $unreachable = new TransactionalMailer(new SmtpServerPool('smtp://127.0.0.1:1'), $this->service(MailConnectionFactory::class), $this->service(SendLog::class), $site);
        $contact = new ContactService($unreachable, $this->service(SubscriberRepository::class), $this->service(MessageRepository::class), $this->service(MessageLog::class),
            $this->service(Engagement::class), $site, $this->service(ClockInterface::class), $this->service(LoggerInterface::class));

        $message = $contact->submit(['name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => 'Hello'], ['subscriber_uuid' => $uuid, 'muid' => $muid] + self::CONTEXT);

        self::assertStringStartsWith('There was a transmission problem.', $message);
        self::assertEmailCount(0);
        self::assertStringEndsWith("EMAIL-FAIL\n", (string) file_get_contents($this->log), 'the submission itself is kept in the log');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM sendlog'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM smlog WHERE sml_muid = ?', [$muid]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]));
    }

    private function email(int $index): Email
    {
        $message = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $message);
        return $message;
    }

    /**
     * @param array<Address> $addresses
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(static fn(Address $address): string => strtolower($address->getAddress()), $addresses));
    }
}
