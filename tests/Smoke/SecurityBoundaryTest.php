<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Security boundaries over real HTTP: server-controlled fields cannot be
 * submitted, subscriber actions that call catto-mail are rate-limited, the
 * worker's configuration is compared with the web server's, and the health
 * check reveals nothing. Persistent fixture subscriber: smoke-boundary@ctnlist.test.
 */
final class SecurityBoundaryTest extends SmokeTestCase
{
    private const EMAIL = 'smoke-boundary@ctnlist.test';

    /** @var array{s_id: int, s_uuid: string} */
    private static array $subscriber;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$db->prepare('INSERT INTO subscribers (s_email, s_fname) VALUES (?, ?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute([self::EMAIL, 'Boundary']);
        /** @var array{s_id: int, s_uuid: string} $row */
        $row = self::rows('SELECT s_id, s_uuid FROM subscribers WHERE s_email = ?', [self::EMAIL])[0];
        self::$subscriber = $row;
    }

    /**
     * A subscriber editing their own record gets no priority or list fields;
     * adding them to the request is refused as a whole (nothing saved).
     */
    public function testASubscriberCannotSubmitServerControlledFields(): void
    {
        $client = self::signedIn();
        $page = '/subscribers/' . self::$subscriber['s_uuid'];
        $before = (int) self::value('SELECT s_priority FROM subscribers WHERE s_id = ?', [self::$subscriber['s_id']]);
        self::assertStringNotContainsString('subscriber[priority]', self::request($client, 'GET', $page)['body'], 'no priority field for the subscriber');

        $crafted = self::submitForm($client, $page, 'subscriber', ['subscriber[firstName]' => 'Changed', 'subscriber[priority]' => '100000', 'subscriber[listId]' => '1']);
        self::assertSame(422, $crafted['status']);
        self::assertStringContainsString('This form should not contain extra fields.', $crafted['body']);
        self::assertSame('Boundary', self::value('SELECT s_fname FROM subscribers WHERE s_id = ?', [self::$subscriber['s_id']]), 'nothing saved');
        self::assertSame($before, (int) self::value('SELECT s_priority FROM subscribers WHERE s_id = ?', [self::$subscriber['s_id']]));
    }

    /** Requests and withdrawals of the catto-mail global opt-out: six per hour, then 429. */
    public function testOptOutChangesAreRateLimited(): void
    {
        $client = self::signedIn();
        $csrf = self::csrfToken(self::request($client, 'GET', '/profile/subscriber/' . self::$subscriber['s_uuid'])['body']);
        for ($i = 1; $i <= 6; $i++) {
            self::assertSame(302, self::request($client, 'POST', '/no-contact/withdraw', ['csrf' => $csrf])['status'], "withdrawal {$i} (nothing to withdraw)");
        }
        $refused = self::request($client, 'POST', '/no-contact/withdraw', ['csrf' => $csrf]);
        self::assertSame(429, $refused['status']);
        self::assertStringContainsString('too many times recently', $refused['body']);
    }

    /** A worker that ran with another configuration (other .env, database or keys) is flagged on the Delivery page. */
    public function testTheDeliveryPageFlagsAWorkerWithAnotherConfiguration(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        self::$db->prepare("INSERT INTO options (o_key, o_value) VALUES ('cattomail:worker_config', 'aaaaaaaaaaaa') ON CONFLICT (o_key) DO UPDATE SET o_value = EXCLUDED.o_value")->execute();
        try {
            self::assertStringContainsString('different configuration from this web server', self::request($client, 'GET', '/delivery')['body']);
        } finally {
            self::$db->prepare("DELETE FROM options WHERE o_key = 'cattomail:worker_config'")->execute();
        }
        self::assertStringNotContainsString('different configuration from this web server', self::request($client, 'GET', '/delivery')['body'], 'no worker record, no warning');
    }

    public function testTheHealthCheckRevealsNothing(): void
    {
        $response = self::request(self::client(), 'GET', '/health');
        self::assertSame(200, $response['status']);
        self::assertSame("OK\n", $response['body']);
        self::assertStringStartsWith('text/plain', $response['headers']['content-type'] ?? '');
        self::assertSame([], $response['cookies']);
        self::assertSame(405, self::request(self::client(), 'POST', '/health')['status']);
    }

    private static function signedIn(): \CurlHandle
    {
        $client = self::client();
        $response = self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken(self::$subscriber['s_id']));
        self::assertSame(302, $response['status'], 'signed in');
        return $client;
    }
}
