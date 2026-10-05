<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Subscriber administration and the Ecwid endpoint. */
final class AdminSubscribersTest extends SmokeTestCase
{
    /** Persistent: subscribers with consent events cannot be deleted. */
    private const ECWID = 'smoke-ecwid@ctnlist.test';

    private ?string $originalName = null;

    protected function tearDown(): void
    {
        if ($this->originalName !== null) {
            self::$db->prepare('UPDATE subscribers SET s_fname = ? WHERE s_id = ?')->execute([$this->originalName, self::$admin['s_id']]);
        }
        parent::tearDown();
    }

    public function testListSearchAndEdit(): void
    {
        $this->originalName = (string) self::$db->query('SELECT s_fname FROM subscribers WHERE s_id = ' . self::$admin['s_id'])->fetchColumn();
        $client = self::client();
        self::loginAsAdmin($client);

        $list = self::request($client, 'GET', '/subscribers?e=admin%40ctnlist&r=5')['body'];
        self::assertStringContainsString(self::$admin['s_email'], $list);
        self::assertStringContainsString('ALL:confirmed', $list);
        self::assertStringContainsString('There are no matching subscribers.', self::request($client, 'GET', '/subscribers?e=no-such-person')['body']);

        $form = self::request($client, 'GET', '/subscribe/' . self::$admin['s_uuid'])['body'];
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $form, $csrf));
        $saved = self::request($client, 'POST', '/subscribe', [
            'csrf' => html_entity_decode($csrf[1]), 'subscriber_token' => self::$admin['s_uuid'], 's_fname' => 'Dev', 's_lname' => 'Admin', 's_priority' => '0',
        ]);
        self::assertSame(302, $saved['status']);
        self::assertStringContainsString('Subscriber saved.', self::request($client, 'GET', '/subscribe/' . self::$admin['s_uuid'])['body']);

        self::assertSame(404, self::request($client, 'GET', '/subscribe/01a10309-8535-7ba9-a26a-000000000000')['status']);
        self::assertSame(403, self::request(self::client(), 'GET', '/subscribe/' . self::$admin['s_uuid'])['status'], 'anonymous');
    }

    public function testEcwidEndpointAddsAPendingMember(): void
    {
        $ok = self::request(self::client(), 'POST', '/ecwid-subscribe', ['email' => self::ECWID]);
        self::assertSame(200, $ok['status']);
        self::assertSame('{"success":true}', $ok['body']);
        $confirmed = self::$db->prepare(
            "SELECT ls.ls_confirmed FROM list_subscribers ls JOIN subscribers s ON s.s_id = ls.ls_s_id JOIN lists l ON l.l_id = ls.ls_l_id
             WHERE s.s_email = ? AND l.l_shortcode = 'ALL'"
        );
        $confirmed->execute([self::ECWID]);
        self::assertFalse((bool) $confirmed->fetchColumn(), 'no consent from an integration');

        $bad = self::request(self::client(), 'POST', '/ecwid-subscribe', ['email' => 'not-an-address']);
        self::assertSame(400, $bad['status']);
        self::assertSame('{"success":false}', $bad['body']);
    }
}
