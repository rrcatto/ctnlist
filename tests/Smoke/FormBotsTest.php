<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Http\BotCheck;

/**
 * The bot check on the public forms (App\Http\BotCheck): a refused submission
 * gets the page a real one gets, creates and sends nothing, and is flagged in
 * the Site Log and on Flagged activity.
 */
final class FormBotsTest extends SmokeTestCase
{
    private const OLD_CHROME = 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.76 Safari/537.36';

    public function testTheTrapIsOffScreenAndOutOfReach(): void
    {
        foreach (['/login' => 'signin', '/subscribe' => 'subscribe'] as $page => $form) {
            $body = self::request(self::client(), 'GET', $page)['body'];
            $row = self::match('#<div class="form-field app-trap" aria-hidden="true">(.*?)</div>#s', $body, "{$page}: the trap's row is hidden")[1];
            $input = self::match('#<input[^>]*name="' . $form . '\[website\]"[^>]*>#', $row, "{$page}: the trap field")[0];
            self::assertStringContainsString('tabindex="-1"', $input, "{$page}: out of the tab order");
            self::assertStringContainsString('autocomplete="off"', $input, "{$page}: never filled in by the browser");
            self::assertStringContainsString('Leave this field empty', $row);
        }
    }

    public function testRefusedSignInsLookSentButCreateAndSendNothing(): void
    {
        $cases = [
            BotCheck::TRAP => [self::BROWSER, [], ['signin[website]' => 'https://spam.example']],
            BotCheck::OUTDATED_BROWSER => [self::OLD_CHROME, [], []],
            BotCheck::SUSPECT_NETWORK => [self::BROWSER, [BotCheck::SUSPECT_HEADER . ': 1'], []],
        ];
        foreach ($cases as $reason => [$agent, $headers, $fields]) {
            $email = 'smoke-bot-' . bin2hex(random_bytes(4)) . '@ctnlist.test';
            $client = self::client();
            curl_setopt($client, CURLOPT_USERAGENT, $agent);
            curl_setopt($client, CURLOPT_HTTPHEADER, $headers);
            $answer = self::submitForm($client, '/login', 'signin', ['signin[email]' => $email] + $fields);
            self::assertSame(200, $answer['status'], $reason);
            self::assertStringContainsString('If the address is valid, a secure sign-in link has been sent.', $answer['body'], "{$reason}: the usual answer");
            self::assertNothingFor($email, $reason);
            self::assertSame([['POST', '/login', '200', $reason]], self::flagged($email), "{$reason}: flagged in the Site Log");
        }

        $person = 'smoke-bot-person@ctnlist.test';
        self::submitForm(self::client(), '/login', 'signin', ['signin[email]' => $person]);
        self::assertSame(1, (int) self::value('SELECT COUNT(*) FROM auth_login_tokens WHERE LOWER(alt_email) = ?', [$person]), 'a person still gets a link');
        self::assertSame([], self::flagged($person));
    }

    public function testARefusedSubscriptionLooksSentButCreatesNothing(): void
    {
        $email = 'smoke-bot-' . bin2hex(random_bytes(4)) . '@ctnlist.test';
        $allId = (string) self::value("SELECT l_id FROM lists WHERE l_shortcode = 'ALL'");
        $answer = self::submitForm(self::client(), '/subscribe', 'subscribe', ['subscribe[email]' => $email, 'subscribe[listId]' => $allId, 'subscribe[website]' => 'Visit us']);
        self::assertSame(200, $answer['status']);
        self::assertStringContainsString('emailed you a link to confirm your subscription', $answer['body']);
        self::assertNothingFor($email, 'subscribe');
        self::assertSame([['POST', '/subscribe/request', '200', BotCheck::TRAP]], self::flagged($email));
    }

    public function testFlaggedActivityShowsTheRefusedAndTheirAddresses(): void
    {
        $email = 'smoke-bot-' . bin2hex(random_bytes(4)) . '@ctnlist.test';
        $bot = self::client();
        curl_setopt($bot, CURLOPT_USERAGENT, self::OLD_CHROME);
        self::submitForm($bot, '/login', 'signin', ['signin[email]' => $email]);
        self::assertNotSame([], self::flagged($email));

        $admin = self::client();
        self::loginAsAdmin($admin);
        $page = self::request($admin, 'GET', '/flagged');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<td>' . $email . '</td>', $page['body'], 'the address the bot typed in');
        self::assertStringContainsString('Chrome/41.0.2272.76', $page['body'], 'its browser');
        self::assertStringContainsString('New records nobody has confirmed', $page['body']);
        self::assertStringContainsString('Refused as automated: outdated browser', self::request($admin, 'GET', '/sitelog?fl=1&q=' . rawurlencode($email))['body']);
        self::assertSame(403, self::request(self::client(), 'GET', '/flagged')['status']);
    }

    private static function assertNothingFor(string $email, string $case): void
    {
        self::assertSame(0, (int) self::value('SELECT COUNT(*) FROM subscribers WHERE LOWER(s_email) = ?', [$email]), "{$case}: no record created");
        self::assertSame(0, (int) self::value('SELECT COUNT(*) FROM auth_login_tokens WHERE LOWER(alt_email) = ?', [$email]), "{$case}: no link issued");
        self::assertSame(0, (int) self::value('SELECT COUNT(*) FROM sendlog WHERE LOWER(sl_email) = ?', [$email]), "{$case}: nothing sent");
    }

    /**
     * The Site Log's refused submissions for $email. The row is written after
     * the response is sent, so give it a moment.
     *
     * @return list<list<string>>
     */
    private static function flagged(string $email): array
    {
        $rows = [];
        for ($try = 0; $try < 20; $try++) {
            $rows = self::rows('SELECT stl_method, stl_url, stl_status, stl_flag FROM sitelog WHERE stl_flag_email = ? ORDER BY stl_id', [$email]);
            if ($rows !== []) {
                break;
            }
            usleep(100_000);
        }
        return array_map(static fn(array $row): array => array_map('strval', array_values($row)), $rows);
    }
}
