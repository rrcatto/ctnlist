<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Recipient actions on a delivered message: like, resend, forward and the open pixel. */
final class MessageActionTest extends SmokeTestCase
{
    /** Persistent: subscribers with consent events cannot be deleted. */
    private const FRIEND = 'smoke-forward@ctnlist.test';

    private static string $muid;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$muid = bin2hex(random_bytes(16));
        $id = (int) self::value("INSERT INTO messages (m_uniqid, m_subject, m_html, m_text) VALUES ('" . self::$muid . "', 'Action fixture', '<p>Hi {firstname}</p>', 'Hi') RETURNING m_id");
        self::$db->exec("INSERT INTO message_lists (ml_m_id, ml_l_id) SELECT {$id}, l_id FROM lists WHERE l_shortcode = 'ALL'");
        self::$db->prepare(
            "INSERT INTO smlog (sml_s_uuid, sml_email, sml_muid, sml_list_shortcode, sml_date_sent) VALUES (?, ?, ?, 'ALL', LOCALTIMESTAMP)"
        )->execute([self::$admin['s_uuid'], self::$admin['s_email'], self::$muid]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM smlog WHERE sml_muid = ?')->execute([self::$muid]);
        self::$db->prepare('DELETE FROM messages WHERE m_uniqid = ?')->execute([self::$muid]);
        parent::tearDownAfterClass();
    }

    public function testLikeResendAndForward(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $uuid = self::$admin['s_uuid'];

        $like = self::submit($client, "/like/{$uuid}/" . self::$muid, '/like', ['subscriber_token' => $uuid, 'muid' => self::$muid]);
        self::assertStringContainsString('Thank you for your LIKE.', $like);

        $resend = self::submit($client, "/resend/{$uuid}/" . self::$muid, '/resend', ['subscriber_token' => $uuid, 'muid' => self::$muid]);
        self::assertStringContainsString('The message could not be sent.', $resend, 'the development stack has no catto-mail configured');

        $tooMany = implode(' ', array_map(static fn(int $n): string => "friend{$n}@example.com", range(1, 11)));
        $invalid = self::submitForm($client, "/forward/{$uuid}/" . self::$muid, 'forward', ['forward[emails]' => $tooMany]);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="forward_emails_error1">Enter no more than 10 different addresses#', $invalid['body']);
        self::assertStringContainsString('friend11@example.com</textarea>', $invalid['body'], 'the addresses are kept');

        $sent = self::submitForm($client, "/forward/{$uuid}/" . self::$muid, 'forward', ['forward[emails]' => self::FRIEND]);
        self::assertSame(200, $sent['status']);
        self::assertStringContainsString('Forwarded the current message to 0 recipient(s).', $sent['body'], 'no catto-mail in development: nothing sent');

        $counts = self::rows("SELECT m_likes, m_reads FROM messages WHERE m_uniqid = '" . self::$muid . "'")[0];
        self::assertSame(['m_likes' => 1, 'm_reads' => 1], $counts);
    }

    public function testOpenPixelAndForeignTokens(): void
    {
        $pixel = self::request(self::client(), 'GET', '/ut/' . self::$admin['s_uuid'] . '/' . self::$muid);
        self::assertSame(200, $pixel['status']);
        self::assertStringStartsWith('GIF89a', $pixel['body']);

        $client = self::client();
        self::loginAsAdmin($client);
        $csrf = self::csrf(self::request($client, 'GET', '/login')['body']);
        $other = '01a10309-8535-7ba9-a26a-000000000000';
        self::assertSame(403, self::request($client, 'POST', '/like', ['csrf' => $csrf, 'subscriber_token' => $other, 'muid' => self::$muid])['status']);
        self::assertSame(404, self::request(self::client(), 'GET', "/resend/{$other}/" . self::$muid)['status'], 'unknown subscriber');
    }

    /** @param array<string, string> $fields */
    private static function submit(\CurlHandle $client, string $formPath, string $action, array $fields): string
    {
        $form = self::request($client, 'GET', $formPath);
        self::assertSame(200, $form['status'], $formPath);
        $response = self::request($client, 'POST', $action, $fields + ['csrf' => self::csrf($form['body'])]);
        self::assertSame(200, $response['status'], $action);
        self::assertCleanPage($action, $response['body']);
        return $response['body'];
    }

    private static function csrf(string $body): string
    {
        $match = self::match('/name="(?:\w+\[)?csrf\]?"[^>]*value="([^"]+)"/', $body);
        return html_entity_decode($match[1]);
    }
}
