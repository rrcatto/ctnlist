<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Archive pages and the contact form. */
final class ArchiveContactTest extends SmokeTestCase
{
    private static int $archiveId;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$archiveId = (int) self::value("INSERT INTO archives (a_subject, a_html) VALUES ('Archive fixture', '<p id=\"archived\">Archived copy</p>') RETURNING a_id");
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM archives WHERE a_id = ?')->execute([self::$archiveId]);
        parent::tearDownAfterClass();
    }

    public function testArchivePageCountsViewsAndOffersForwarding(): void
    {
        self::withSetting('APP_ARCHIVE_ENABLED', 'true', function (): void {
            $anonymous = self::request(self::client(), 'GET', '/archive/' . self::$archiveId)['body'];
            self::assertStringContainsString('<p id="archived">Archived copy</p>', $anonymous, 'archived HTML rendered as is');
            self::assertStringNotContainsString('id="forward-archive"', $anonymous);

            $client = self::client();
            self::loginAsAdmin($client);
            self::assertStringContainsString('id="forward-archive"', self::request($client, 'GET', '/archive/' . self::$archiveId)['body']);
            $invalid = self::submitForm($client, '/archive/' . self::$archiveId, 'forward', ['forward[emails]' => 'nobody']);
            self::assertSame(422, $invalid['status'], 'invalid input shows the archive again');
            self::assertMatchesRegularExpression('#id="forward_emails_error1">No usable email addresses were found#', $invalid['body']);
            self::assertStringContainsString('<p id="archived">Archived copy</p>', $invalid['body']);
            self::assertSame(3, (int) self::value('SELECT a_viewed FROM archives WHERE a_id = ' . self::$archiveId));

            self::assertStringContainsString('Archive fixture', self::request(self::client(), 'GET', '/archives')['body']);
            self::assertSame(404, self::request(self::client(), 'GET', '/archive/999999')['status']);
        });
    }

    public function testDisabledArchivesAreNotPublished(): void
    {
        self::withSetting('APP_ARCHIVE_ENABLED', 'false', function (): void {
            foreach (['/archives', '/archives/1', '/archive/' . self::$archiveId] as $path) {
                self::assertSame(404, self::request(self::client(), 'GET', $path)['status'], $path);
            }
            self::assertStringNotContainsString('href="/archives"', self::request(self::client(), 'GET', '/')['body']);
        });
    }

    public function testContactSubmission(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $invalid = self::submitForm($client, '/contact-form', 'contact', ['contact[name]' => 'Smoke Visitor', 'contact[email]' => 'not-an-address', 'contact[message]' => 'Kept message']);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="contact_email_error1">Enter a valid email address#', $invalid['body']);
        self::assertStringContainsString('value="Smoke Visitor"', $invalid['body'], 'values are kept');
        self::assertStringContainsString('>Kept message</textarea>', $invalid['body']);
        self::assertStringNotContainsString('Thank you for your submission!', $invalid['body'], 'nothing is sent');

        // Every run submits from the same container address; the hourly limit itself is covered by RequestThrottleTest.
        self::withSetting('CONTACT_RATE_LIMIT', '100000', function () use ($client): void {
            $response = self::submitForm($client, '/contact-form', 'contact', [
                'contact[name]' => 'Smoke Visitor', 'contact[email]' => 'visitor@example.com', 'contact[topic]' => 'Smoke', 'contact[message]' => 'Hello',
            ]);
            self::assertSame(200, $response['status']);
            self::assertStringContainsString('Thank you for your submission!', $response['body']);
        });

        self::withSetting('CONTACT_RATE_LIMIT', '1', function () use ($client): void {
            $fields = ['contact[name]' => 'Smoke Visitor', 'contact[email]' => 'limit-' . bin2hex(random_bytes(4)) . '@example.com', 'contact[message]' => 'Hello'];
            self::submitForm($client, '/contact-form', 'contact', $fields);
            $limited = self::submitForm($client, '/contact-form', 'contact', $fields);
            self::assertSame(429, $limited['status'], 'one message per hour for this address');
            self::assertStringContainsString('Too many messages have been sent from here recently.', $limited['body']);
        });

        $prefilled = self::request(self::client(), 'GET', '/contact-form/' . self::$admin['s_uuid'])['body'];
        self::assertStringContainsString('value="' . self::$admin['s_email'] . '"', $prefilled);
    }
}
