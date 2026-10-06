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
        $id = (int) self::$db->query(
            "INSERT INTO messages (m_uniqid, m_subject, m_html, m_text, m_max_send, m_from_address)
             VALUES ('" . self::$muid . "', 'Queue fixture', '<p>Hello {firstname} {unsubscribe}</p>', 'Hello', 1000000, 'list@ctnlist.test') RETURNING m_id"
        )->fetchColumn();
        self::$db->exec("INSERT INTO message_lists (ml_m_id, ml_l_id) SELECT {$id}, l_id FROM lists WHERE l_shortcode = 'ALL'");
    }

    public static function tearDownAfterClass(): void
    {
        $archive = self::$db->query("SELECT m_a_id FROM messages WHERE m_uniqid = '" . self::$muid . "'")->fetchColumn();
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
        $rows = (int) self::$db->query("SELECT COUNT(*) FROM queue WHERE q_muid = '{$muid}'")->fetchColumn();
        self::assertStringContainsString('Queue fixture', self::request($client, 'GET', '/queue')['body']);
        self::assertStringContainsString('Finished queueing: 0', self::submit($client, '/queuelist/' . $muid, '/queuelist', ['muid' => $muid, 'limit' => '500000']), 'once-only');

        $sent = self::submit($client, '/processqueue/' . $muid, '/processqueue', ['muid' => $muid, 'limit' => '1000']);
        self::assertStringContainsString('Sent ' . $rows . ' message(s).', $sent);
        self::assertSame(0, (int) self::$db->query("SELECT COUNT(*) FROM queue WHERE q_muid = '{$muid}'")->fetchColumn());
        self::assertSame($rows, (int) self::$db->query("SELECT COUNT(*) FROM sendlog WHERE sl_muid = '{$muid}' AND sl_type = 'MESSAGE'")->fetchColumn());

        $proof = self::submit($client, '/sendtome/' . $muid, '/sendtome/' . $muid, []);
        self::assertMatchesRegularExpression('/Proof message sent to [^<]+@[^<]+\./', $proof, 'to MAIL_TEST_ADDRESS when no address is entered');

        self::assertStringContainsString('The stop request has been recorded.', self::submit($client, '/stop-send', '/stop-send', []));
        self::assertSame('N', self::$db->query("SELECT o_value FROM options WHERE o_key = 'SendQueue'")->fetchColumn());
    }

    public function testRotationFormAndRefusals(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        self::assertStringContainsString('Queue fixture', self::request($client, 'GET', '/advanced-queue')['body'], 'messages offered');
        self::assertStringContainsString('Select at least one message before queueing.', self::submit($client, '/advanced-queue', '/advanced-queue', ['mvolume' => '10']));
        self::assertSame(403, self::request(self::client(), 'POST', '/queue/clear', [])['status']);
    }

    /** @param array<string, string> $fields */
    private static function submit(\CurlHandle $client, string $formPath, string $action, array $fields): string
    {
        $form = self::request($client, 'GET', $formPath);
        self::assertSame(200, $form['status'], $formPath);
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $form['body'], $match), $formPath);
        $response = self::request($client, 'POST', $action, $fields + ['csrf' => html_entity_decode($match[1])]);
        self::assertSame(200, $response['status'], $action);
        self::assertCleanPage($action, $response['body']);
        return $response['body'];
    }
}
