<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Queueing, sending, proofs and stopping, through the administrator pages. */
final class AdminQueueTest extends SmokeTestCase
{
    private static string $muid;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$muid = bin2hex(random_bytes(16));
        $id = (int) self::value("INSERT INTO messages (m_uniqid, m_subject, m_html, m_text, m_max_send, m_from_address)
             VALUES ('" . self::$muid . "', 'Queue fixture', '<p>Hello {firstname} {unsubscribe}</p>', 'Hello', 1000000, 'list@ctnlist.test') RETURNING m_id");
        self::$db->exec("INSERT INTO message_lists (ml_m_id, ml_l_id) SELECT {$id}, l_id FROM lists WHERE l_shortcode = 'ALL'");
    }

    public static function tearDownAfterClass(): void
    {
        $archive = self::value("SELECT m_a_id FROM messages WHERE m_uniqid = '" . self::$muid . "'");
        foreach (['DELETE FROM queue WHERE q_muid = ?', 'DELETE FROM smlog WHERE sml_muid = ?', 'DELETE FROM messages WHERE m_uniqid = ?'] as $sql) {
            self::$db->prepare($sql)->execute([self::$muid]);
        }
        if ($archive) {
            self::$db->prepare('DELETE FROM archives WHERE a_id = ?')->execute([$archive]);
        }
        parent::tearDownAfterClass();
    }

    public function testQueueProcessProofAndStop(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $muid = self::$muid;

        $queued = self::submit($client, '/queuelist/' . $muid, '/queuelist', ['muid' => $muid, 'limit' => '500000']);
        self::assertMatchesRegularExpression('/Finished queueing: [1-9]\d*/', $queued, 'at least the administrator (confirmed ALL member)');
        $rows = (int) self::value("SELECT COUNT(*) FROM queue WHERE q_muid = '{$muid}'");
        self::assertStringContainsString('Queue fixture', self::request($client, 'GET', '/queue')['body']);
        self::assertStringContainsString('Finished queueing: 0', self::submit($client, '/queuelist/' . $muid, '/queuelist', ['muid' => $muid, 'limit' => '500000']), 'once-only');

        // The development stack has no catto-mail configured: nothing is sent and nothing is lost
        // (catto-mail delivery itself is covered by the integration tests with a fake catto-mail).
        $sent = self::submit($client, '/processqueue/' . $muid, '/processqueue', ['muid' => $muid, 'limit' => '1000']);
        self::assertStringContainsString('Handed 0 message(s) to catto-mail.', $sent);
        self::assertStringContainsString('catto-mail is not configured', $sent);
        self::assertSame($rows, (int) self::value("SELECT COUNT(*) FROM queue WHERE q_muid = '{$muid}'"), 'still queued');
        self::assertSame(0, (int) self::value("SELECT COUNT(*) FROM sendlog WHERE sl_muid = '{$muid}' AND sl_type = 'MESSAGE'"));
        self::assertNotSame('Y', self::value("SELECT o_value FROM options WHERE o_key = 'CurrentlySending'"), 'not left marked as sending');

        $sent = self::submitForm($client, '/sendtome/' . $muid, 'proof', ['proof[email]' => '']);
        self::assertSame(200, $sent['status']);
        self::assertStringContainsString('Proof message could not be sent: catto-mail is not configured', $sent['body']);
        self::$db->prepare('DELETE FROM queue WHERE q_muid = ?')->execute([$muid]);

        self::assertStringContainsString('The stop request has been recorded.', self::submit($client, '/stop-send', '/stop-send', []));
        self::assertSame('N', self::value("SELECT o_value FROM options WHERE o_key = 'SendQueue'"));
    }

    public function testRotationFormAndRefusals(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        self::assertStringContainsString('Queue fixture', self::request($client, 'GET', '/advanced-queue')['body'], 'messages offered');
        $none = self::submitForm($client, '/advanced-queue', 'rotation', ['rotation[volume]' => '10']);
        self::assertSame(422, $none['status']);
        self::assertStringContainsString('Select at least one message before queueing.', $none['body']);
        $forged = self::submitForm($client, '/advanced-queue', 'rotation', ['rotation[message1]' => str_repeat('f', 32), 'rotation[volume]' => '0']);
        self::assertSame(422, $forged['status']);
        self::assertStringContainsString('Choose one of the listed messages.', $forged['body'], 'only listed messages');
        self::assertStringContainsString('Queue between 1 and 10000000 emails.', $forged['body']);
        self::assertSame(403, self::request(self::client(), 'POST', '/queue/clear', [])['status']);
    }

    /** @param array<string, string> $fields */
    private static function submit(\CurlHandle $client, string $formPath, string $action, array $fields): string
    {
        $form = self::request($client, 'GET', $formPath);
        self::assertSame(200, $form['status'], $formPath);
        $match = self::match('/name="(?:\w+\[)?csrf\]?"[^>]*value="([^"]+)"/', $form['body'], $formPath);
        $response = self::request($client, 'POST', $action, $fields + ['csrf' => html_entity_decode($match[1])]);
        self::assertSame(200, $response['status'], $action);
        self::assertCleanPage($action, $response['body']);
        return $response['body'];
    }
}
