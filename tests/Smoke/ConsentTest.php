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
        $id = (int) self::value("INSERT INTO messages (m_uniqid, m_subject) VALUES ('" . self::$muid . "', 'Consent fixture') RETURNING m_id");
        self::$db->exec("INSERT INTO message_lists (ml_m_id, ml_l_id) SELECT {$id}, l_id FROM lists WHERE l_shortcode = 'ALL'");
        // A second list, so the subscribe page offers a choice (the smoke database starts with ALL only).
        self::$db->exec("INSERT INTO lists (l_shortcode, l_name) VALUES ('SMOKE', 'Smoke list') ON CONFLICT DO NOTHING");
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

        self::assertSame(1, (int) self::value("SELECT sml_unsubscribe + sml_confirms - 1 FROM smlog WHERE sml_muid = '" . self::$muid . "'"), 'both actions logged against the message');
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
        self::assertStringContainsString('action="/subscribe/request"', $general);
        self::assertStringContainsString('name="subscribe[listId]"', $general, 'visitors choose a list');

        // Invalid input: the form again, with the address and list kept and nothing sent.
        $allId = (string) self::value("SELECT l_id FROM lists WHERE l_shortcode = 'ALL'");
        $visitor = self::client();
        $invalid = self::submitForm($visitor, '/subscribe', 'subscribe', ['subscribe[email]' => 'not-an-address', 'subscribe[listId]' => $allId]);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="subscribe_email_error1">Enter a valid email address#', $invalid['body']);
        self::assertStringContainsString('value="not-an-address"', $invalid['body']);
        self::assertMatchesRegularExpression('#name="subscribe\[listId\]" value="' . $allId . '" checked#', $invalid['body'], 'the chosen list is kept');
        $noList = self::submitForm($visitor, '/subscribe', 'subscribe', ['subscribe[email]' => 'smoke-editor@ctnlist.test', 'subscribe[listId]' => null]);
        self::assertSame(422, $noList['status']);
        self::assertStringContainsString('Choose a list.', $noList['body']);
        $sent = self::submitForm($visitor, '/subscribe', 'subscribe', ['subscribe[email]' => 'smoke-editor@ctnlist.test', 'subscribe[listId]' => $allId]);
        self::assertSame(200, $sent['status']);
        self::assertStringContainsString('emailed you a link to confirm your subscription', $sent['body'], 'subscription wording, not sign-in');
        self::assertStringNotContainsString('Sign in by email', $general);

        $anonymous = self::request(self::client(), 'GET', '/subscribe?m=' . self::$muid);
        self::assertStringContainsString('Subscribe to ALL', $anonymous['body'], 'the message\'s only list');
        self::assertStringContainsString('action="/subscribe/request"', $anonymous['body']);
        self::assertStringContainsString('name="subscribe[messageId]"', $anonymous['body'], 'the message is passed on');

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
        $match = self::match('/name="(?:\w+\[)?csrf\]?"[^>]*value="([^"]+)"/', $body);
        return html_entity_decode($match[1]);
    }
}
