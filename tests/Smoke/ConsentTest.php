<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Consent links (/confirm, /unsubscribe) and the /subscribe entry point. */
final class ConsentTest extends SmokeTestCase
{
    private static string $muid;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$muid = bin2hex(random_bytes(16));
        $id = (int) self::$db->query(
            "INSERT INTO messages (m_uniqid, m_subject) VALUES ('" . self::$muid . "', 'Consent fixture') RETURNING m_id"
        )->fetchColumn();
        self::$db->exec("INSERT INTO message_lists (ml_m_id, ml_l_id) SELECT {$id}, l_id FROM lists WHERE l_shortcode = 'ALL'");
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM smlog WHERE sml_muid = ?')->execute([self::$muid]);
        self::$db->prepare('DELETE FROM messages WHERE m_uniqid = ?')->execute([self::$muid]);
        parent::tearDownAfterClass();
    }

    public function testUnsubscribeAndConfirmRoundTrip(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $uuid = self::$admin['s_uuid'];

        $form = self::request($client, 'GET', "/unsubscribe/{$uuid}/ALL/" . self::$muid);
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('Unsubscribe from ALL', $form['body']);
        $done = self::request($client, 'POST', '/unsubscribe', [
            'csrf' => self::csrf($form['body']), 'subscriber_token' => $uuid, 'list_shortcode' => 'ALL', 'muid' => self::$muid, 'scope' => 'list', 'reason' => 'smoke test',
        ]);
        self::assertStringContainsString('You have been unsubscribed from ALL.', $done['body']);
        self::assertFalse($this->allConfirmed());

        // Restores the administrator's consent, so this also leaves the dev data as it was.
        $form = self::request($client, 'GET', "/confirm/{$uuid}/ALL/" . self::$muid);
        self::assertStringContainsString('Confirm subscription to ALL', $form['body']);
        $done = self::request($client, 'POST', '/confirm', [
            'csrf' => self::csrf($form['body']), 'subscriber_token' => $uuid, 'list_shortcode' => 'ALL', 'muid' => self::$muid,
        ]);
        self::assertStringContainsString('Your subscription to ALL is confirmed.', $done['body']);
        self::assertTrue($this->allConfirmed());

        self::assertSame(1, (int) self::$db->query("SELECT sml_unsubscribe + sml_confirms - 1 FROM smlog WHERE sml_muid = '" . self::$muid . "'")->fetchColumn(), 'both actions logged against the message');
    }

    public function testOtherPeoplesLinksAndBadLinks(): void
    {
        $uuid = self::$admin['s_uuid'];
        $prompt = self::request(self::client(), 'GET', "/confirm/{$uuid}/ALL");
        self::assertSame(200, $prompt['status']);
        self::assertStringContainsString('Authentication required', $prompt['body']);

        self::assertSame(404, self::request(self::client(), 'GET', "/confirm/{$uuid}/NOLIST")['status']);
        self::assertSame(404, self::request(self::client(), 'GET', '/unsubscribe/01a10309-8535-7ba9-a26a-000000000000/ALL')['status']);

        $anonymous = self::client();
        $csrf = self::csrf(self::request($anonymous, 'GET', '/login')['body']);
        $forged = self::request($anonymous, 'POST', '/confirm', ['csrf' => $csrf, 'subscriber_token' => $uuid, 'list_shortcode' => 'ALL']);
        self::assertSame(403, $forged['status'], 'only the subscriber may act on their link');
    }

    public function testSubscribeEntryPoint(): void
    {
        $general = self::request(self::client(), 'GET', '/subscribe')['body'];
        self::assertMatchesRegularExpression('#<h1 class="h3 mb-1">Subscribe( to [^<]+)?</h1>#', $general, 'a subscribe form, not a sign-in page');
        self::assertStringContainsString('name="return_action" value="confirm"', $general);
        self::assertStringContainsString('name="return_list_id"', $general, 'visitors choose a list');
        self::assertStringNotContainsString('Sign in by email', $general);

        $anonymous = self::request(self::client(), 'GET', '/subscribe?m=' . self::$muid);
        self::assertStringContainsString('Subscribe to ALL', $anonymous['body'], 'the message\'s only list');
        self::assertStringContainsString('name="return_action" value="confirm"', $anonymous['body']);

        $client = self::client();
        self::loginAsAdmin($client);
        $page = self::request($client, 'GET', '/subscribe');
        self::assertSame(200, $page['status'], 'signed-in subscribers are not asked to sign in');
        self::assertStringNotContainsString('name="email"', $page['body']);
        self::assertMatchesRegularExpression('#href="/(confirm|unsubscribe)/' . self::$admin['s_uuid'] . '/ALL"#', $page['body'], 'their lists with actions');

        $signedIn = self::request($client, 'GET', '/subscribe?m=' . self::$muid);
        self::assertSame(302, $signedIn['status']);
        self::assertStringEndsWith('/confirm/' . self::$admin['s_uuid'] . '/ALL/' . self::$muid, $signedIn['location']);
    }

    private function allConfirmed(): bool
    {
        $state = self::$db->prepare(
            "SELECT ls_confirmed AND NOT ls_unsubscribed FROM list_subscribers ls JOIN lists l ON l.l_id = ls.ls_l_id WHERE ls.ls_s_id = ? AND l.l_shortcode = 'ALL'"
        );
        $state->execute([self::$admin['s_id']]);
        return (bool) $state->fetchColumn();
    }

    private static function csrf(string $body): string
    {
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $body, $match));
        return html_entity_decode($match[1]);
    }
}
