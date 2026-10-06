<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Subscriber administration. */
final class AdminSubscribersTest extends SmokeTestCase
{
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
        self::assertMatchesRegularExpression('#ALL <span class="badge text-bg-success">confirmed</span>#', $list, 'membership badge');
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
}
