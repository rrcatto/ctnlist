<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriber;

use App\Config\SiteConfig;
use App\Subscriber\ContactService;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Mime\Email;

final class ContactServiceTest extends IntegrationTestCase
{
    public function testSubmissionIsLoggedEscapedAndAcknowledged(): void
    {
        $log = $this->service(SiteConfig::class)->contactLogFile;
        @unlink($log);
        $id = $this->createSubscriber('jane@example.com');
        $uuid = $this->subscriberUuid($id);
        $muid = $this->createMessage('Offer');

        $message = $this->service(ContactService::class)->submit(
            ['name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => '<script>alert(1)</script>'],
            ['subscriber_uuid' => $uuid, 'muid' => $muid, 'ip' => '10.0.0.1', 'user_agent' => 'test', 'forwarded_for' => '']
        );

        self::assertStringStartsWith('Thank you for your submission!', $message);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringNotContainsString('<script>', (string) $email->getHtmlBody(), 'submitted values are escaped');
        self::assertStringContainsString("SubscriberEmail:\njane@example.com", (string) $email->getTextBody());
        $written = (string) file_get_contents($log);
        self::assertStringContainsString("Comments:\n<script>alert(1)</script>", $written);
        self::assertStringEndsWith("EMAIL-OK\n", $written);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT sml_bookings FROM smlog WHERE sml_muid = ?', [$muid]));
    }
}
