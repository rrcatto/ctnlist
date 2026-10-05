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
        self::$archiveId = (int) self::$db->query(
            "INSERT INTO archives (a_subject, a_html) VALUES ('Archive fixture', '<p id=\"archived\">Archived copy</p>') RETURNING a_id"
        )->fetchColumn();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM archives WHERE a_id = ?')->execute([self::$archiveId]);
        parent::tearDownAfterClass();
    }

    public function testArchivePageCountsViewsAndOffersForwarding(): void
    {
        $anonymous = self::request(self::client(), 'GET', '/archive/' . self::$archiveId)['body'];
        self::assertStringContainsString('<p id="archived">Archived copy</p>', $anonymous, 'archived HTML rendered as is');
        self::assertStringNotContainsString('forwardarchiveform', $anonymous);

        $client = self::client();
        self::loginAsAdmin($client);
        self::assertStringContainsString('forwardarchiveform', self::request($client, 'GET', '/archive/' . self::$archiveId)['body']);
        self::assertSame(2, (int) self::$db->query('SELECT a_viewed FROM archives WHERE a_id = ' . self::$archiveId)->fetchColumn());

        self::assertStringContainsString('Archive fixture', self::request(self::client(), 'GET', '/archives')['body']);
        self::assertSame(404, self::request(self::client(), 'GET', '/archive/999999')['status']);
    }

    public function testContactSubmission(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $form = self::request($client, 'GET', '/contact-form')['body'];
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $form, $csrf));

        $response = self::request($client, 'POST', '/contact-form', [
            'csrf' => html_entity_decode($csrf[1]), 'name' => 'Smoke Visitor', 'email' => 'visitor@example.com', 'topic' => 'Smoke', 'message' => 'Hello',
        ]);
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Thank you for your submission!', $response['body']);

        $prefilled = self::request(self::client(), 'GET', '/contact-form/' . self::$admin['s_uuid'])['body'];
        self::assertStringContainsString('value="' . self::$admin['s_email'] . '"', $prefilled);
    }
}
