<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Archive pages and the contact form. */
final class ArchiveContactTest extends SmokeTestCase
{
    private const SUBSCRIBER = 'smoke-contact@ctnlist.test';

    private static int $archiveId;
    private static int $subscriberId;
    private static string $subscriberUuid;
    private static string $muid;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$archiveId = (int) self::value("INSERT INTO archives (a_subject, a_html) VALUES ('Archive fixture', '<p id=\"archived\">Archived copy</p>') RETURNING a_id");
        self::$db->prepare("INSERT INTO subscribers (s_email, s_fname, s_lname, s_phone, s_business) VALUES (?, 'Contacta', 'Smokesdottir', '+27 21 555 0199', 'Smoke Fixtures Ltd')
            ON CONFLICT ((LOWER(s_email))) DO NOTHING")->execute([self::SUBSCRIBER]);
        $subscriber = self::rows('SELECT s_id, s_uuid FROM subscribers WHERE s_email = ?', [self::SUBSCRIBER])[0];
        self::$subscriberId = (int) $subscriber['s_id'];
        self::$subscriberUuid = (string) $subscriber['s_uuid'];
        self::$muid = bin2hex(random_bytes(16));
        self::$db->prepare("INSERT INTO messages (m_uniqid, m_subject, m_html, m_text) VALUES (?, 'Contact fixture', '<p>{contact}</p>', '{contact}')")->execute([self::$muid]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM archives WHERE a_id = ?')->execute([self::$archiveId]);
        self::$db->prepare('DELETE FROM messages WHERE m_uniqid = ?')->execute([self::$muid]);
        self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')->execute([self::$subscriberId, self::$startedAt]);
        self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')->execute([self::$subscriberId, self::$startedAt]);
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
    }

    /**
     * A contact link travels with a forwarded message, so the form shows the
     * subscriber's details to nobody but that subscriber, signed in. Whoever
     * sends it, it is counted for the link's subscriber and message, and the
     * message's activity report shows it.
     */
    public function testContactLinkIsFilledInOnlyForItsOwnSubscriber(): void
    {
        $link = '/contact-form/' . self::$subscriberUuid . '/' . self::$muid;
        $admin = self::client();
        self::loginAsAdmin($admin);
        foreach (['a visitor' => self::client(), 'another subscriber' => $admin] as $who => $client) {
            $page = self::request($client, 'GET', $link);
            self::assertSame(200, $page['status'], $who);
            foreach ([self::SUBSCRIBER, 'Contacta', 'Smokesdottir', '+27 21 555 0199', 'Smoke Fixtures Ltd'] as $detail) {
                self::assertStringNotContainsString($detail, $page['body'], "{$who} is not shown {$detail}");
            }
            $fields = self::formFields($page['body'], 'contact');
            self::assertSame(self::$subscriberUuid, $fields['contact[suid]'], 'the link still names the subscriber for the record');
            self::assertSame(self::$muid, $fields['contact[muid]']);
        }

        $own = self::client();
        self::assertSame(302, self::signInWithLink($own, self::issueLoginToken(self::$subscriberId))['status']);
        $fields = self::formFields(self::request($own, 'GET', $link)['body'], 'contact');
        self::assertSame(['Contacta Smokesdottir', self::SUBSCRIBER, '+27 21 555 0199', 'Smoke Fixtures Ltd'],
            [$fields['contact[name]'], $fields['contact[email]'], $fields['contact[cell]'], $fields['contact[company]']], 'their own details');

        // Someone the message was forwarded to answers it.
        self::withSetting('CONTACT_RATE_LIMIT', '100000', function () use ($link): void {
            $response = self::submitForm(self::client(), $link, 'contact', [
                'contact[name]' => 'Forwarded Reader', 'contact[email]' => 'smoke-forwarded-reader@example.com', 'contact[message]' => 'Please call me',
            ]);
            self::assertSame(200, $response['status']);
            self::assertStringContainsString('Thank you for your submission!', $response['body']);
            self::assertStringNotContainsString(self::SUBSCRIBER, $response['body']);
        });
        self::assertSame(1, (int) self::value('SELECT sml_bookings FROM smlog WHERE sml_s_uuid = ? AND sml_muid = ?', [self::$subscriberUuid, self::$muid]));

        $report = self::request($admin, 'GET', '/message-views/' . self::$muid)['body'];
        self::assertStringContainsString('>Contact forms</th><th scope="col">Last contact form</th>', $report);
        $row = self::match('#<tr>\s*<td>' . preg_quote(self::SUBSCRIBER, '#') . '</td>(.*?)</tr>#s', $report, 'the subscriber is listed')[1];
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $row, $cells);
        // After the address: list, reads, last read, likes, last like, dislikes, last dislike, forwards, contact forms, last contact form.
        self::assertSame('1', $cells[1][8], 'one contact form');
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $cells[1][9]);
    }
}
