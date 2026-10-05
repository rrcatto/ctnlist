<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** The Send Log, Site Log and message activity reports, and site access logging. */
final class ReportsTest extends SmokeTestCase
{
    public function testEveryRequestIsRecordedInTheSiteLog(): void
    {
        $marker = '/privacy?' . bin2hex(random_bytes(4));
        $agent = 'ctnlist-smoke-' . bin2hex(random_bytes(4));
        $client = self::client();
        curl_setopt($client, CURLOPT_USERAGENT, $agent);
        self::assertSame(200, self::request($client, 'GET', $marker)['status']);
        self::assertSame(404, self::request($client, 'GET', '/no-such-page')['status']);

        $rows = self::$db->prepare('SELECT stl_url, stl_logged_in, stl_email, stl_logged_at FROM sitelog WHERE stl_agent = ? ORDER BY stl_id');
        $rows->execute([$agent]);
        $logged = $rows->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['/privacy', '/no-such-page'], array_column($logged, 'stl_url'), 'path only, unknown pages too');
        self::assertFalse((bool) $logged[0]['stl_logged_in']);
        self::assertNull($logged[0]['stl_email']);
        self::assertLessThan(120, abs(strtotime((string) $logged[0]['stl_logged_at']) - time()), 'timestamp in PHP time');

        self::loginAsAdmin($client);
        $page = self::request($client, 'GET', '/sitelog?q=' . $agent . '&r=5');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('/no-such-page', $page['body']);
        self::assertStringContainsString('No site accesses match that search.', self::request($client, 'GET', '/sitelog?li=1&u=no-such-page&q=' . $agent)['body'], 'that visit was anonymous');
        self::assertStringContainsString('<td>Yes</td>', self::request($client, 'GET', '/sitelog?li=1&u=sitelog&q=' . $agent)['body'], 'report visits are signed in');
        self::$db->prepare('DELETE FROM sitelog WHERE stl_agent = ?')->execute([$agent]);
    }

    public function testSendLogAndMessageViews(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $email = self::$admin['s_email'];

        $sendLog = self::request($client, 'GET', '/sendlog?t=MAGIC&e=' . rawurlencode($email))['body'];
        self::assertStringContainsString('All time:', $sendLog);
        self::assertStringContainsString('<td>MAGIC-LINK</td>', $sendLog, 'type is a prefix match');
        self::assertStringContainsString('No emails match that search.', self::request($client, 'GET', '/sendlog?e=nobody-at-all')['body']);

        self::assertStringContainsString('No matching subscriber has read this message yet.', self::request($client, 'GET', '/message-views/' . str_repeat('0', 32))['body']);
        self::assertSame(403, self::request(self::client(), 'GET', '/sendlog')['status']);
    }
}
