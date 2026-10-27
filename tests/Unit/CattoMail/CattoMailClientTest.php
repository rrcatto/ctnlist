<?php

declare(strict_types=1);

namespace App\Tests\Unit\CattoMail;

use App\CattoMail\CattoMailClient;
use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailRejected;
use App\CattoMail\CattoMailUnavailable;
use App\Tests\Support\FakeCattoMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The catto-mail HTTP client against the fake API (MockHttpClient). */
final class CattoMailClientTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    public function testAuthenticatesWithTheBearerKeyAndSendsIdempotencyKeys(): void
    {
        [$client, $fake] = $this->client();
        $job = $client->createSendJob('key-0001-create', ['external_reference' => 'run/1', 'message_class' => 'transactional', 'sender_identity' => ['email' => 'a@ctnlist.test']]);
        self::assertSame('collecting', $job['status']);
        $request = $fake->requests[0];
        self::assertSame('Bearer ' . FakeCattoMail::KEY, $request['headers']['authorization']);
        self::assertSame('key-0001-create', $request['headers']['idempotency-key']);
        self::assertSame('/send-jobs', $request['path']);

        $wrongKey = new CattoMailClient(new MockHttpClient($fake->handler(), FakeCattoMail::BASE), new CattoMailConfig('https://cattomail.test', 'shk_wrong', ''), $this->logger());
        try {
            $wrongKey->getSendJob($job['id']);
            self::fail('accepted a wrong key');
        } catch (CattoMailRejected $e) {
            self::assertSame(401, $e->status);
            self::assertStringNotContainsString('shk_wrong', $e->getMessage());
        }
    }

    public function testValidationJobLifecycle(): void
    {
        [$client, $fake] = $this->client();
        $job = $client->createValidationJob('key-validation-1', 'job-ref', [
            ['address' => 'a@example.com', 'external_address_reference' => 'ref-a'],
            ['address' => 'b@example.com', 'external_address_reference' => 'ref-b'],
            ['address' => 'c@example.com', 'external_address_reference' => 'ref-c'],
        ]);
        self::assertSame('queued', $client->getValidationJob($job['id'])['status']);
        $fake->completeValidation($job['id'], ['b@example.com' => ['overall_classification' => 'unknown']]);
        self::assertSame('completed', $client->getValidationJob($job['id'])['status']);

        $first = $client->listValidationAddresses($job['id']);
        self::assertCount(2, $first['data']);
        self::assertNotNull($first['next_cursor']);
        $second = $client->listValidationAddresses($job['id'], $first['next_cursor']);
        self::assertSame(['ref-c'], array_column($second['data'], 'external_address_reference'));
        self::assertNull($second['next_cursor']);
        self::assertSame('unknown', $first['data'][1]['overall_classification']);
    }

    public function testSendJobBatchesSubmitMessagesAndEvents(): void
    {
        [$client, $fake] = $this->client();
        $job = $client->createSendJob('key-send-0001', ['external_reference' => 'run/1', 'message_class' => 'subscription', 'list_id' => 'News <news.ctnlist.test>',
            'sender_identity' => ['email' => 'news@ctnlist.test']]);
        $batch = [self::recipient('r1', 'one@example.com'), self::recipient('r2', 'two@example.com')];
        $result = $client->addRecipients($job['id'], 'key-batch-0001', $batch);
        self::assertSame(2, $result['total_recipients']);
        // The same key and body again is a replay: nothing is added.
        $replay = $client->addRecipients($job['id'], 'key-batch-0001', $batch);
        self::assertSame($result['batch_id'], $replay['batch_id']);
        self::assertCount(2, $fake->recipients[$job['id']]);

        self::assertSame('queued', $client->submitSendJob($job['id'])['status']);
        self::assertSame('queued', $client->submitSendJob($job['id'])['status'], 'submit is naturally idempotent');
        $fake->deliver($job['id'], ['two@example.com' => 'hard_bounced']);
        $messages = $client->listSendJobMessages($job['id']);
        self::assertSame(['remote_accepted', 'hard_bounced'], array_column($messages['data'], 'current_status'));
        $events = $client->listMessageEvents($messages['data'][0]['id']);
        $more = $client->listMessageEvents($messages['data'][0]['id'], $events['next_cursor']);
        self::assertSame(['submitted_to_postfix', 'remote_accepted', 'open_recorded'], array_column([...$events['data'], ...$more['data']], 'event_type'));

        try {
            $client->addRecipients($job['id'], 'key-batch-0002', array_fill(0, 501, self::recipient('x', 'x@example.com')));
            self::fail('accepted a batch over 500');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testGlobalOptOutCreateGetAndLift(): void
    {
        [$client, $fake] = $this->client();
        $optOut = $client->createGlobalOptOut('key-optout-01', 'Person@Example.COM', 'ref-1');
        self::assertSame(['active', 'Person@example.com'], [$optOut['status'], $optOut['email_address']]);
        self::assertSame($optOut['id'], $client->createGlobalOptOut('key-optout-01', 'Person@Example.COM', 'ref-1')['id'], 'replay');
        self::assertCount(1, $fake->optOuts);
        self::assertSame('active', $client->getGlobalOptOut($optOut['id'])['status']);
        self::assertSame('lifted', $client->liftGlobalOptOut($optOut['id'])['status']);

        $fake->optOutCapability = false;
        try {
            $client->createGlobalOptOut('key-optout-02', 'other@example.com', 'ref-2');
            self::fail('opt-out without the capability');
        } catch (CattoMailRejected $e) {
            self::assertSame(403, $e->status);
        }
    }

    public function testTimeoutsAndServerErrorsAreRetriedWithTheSameKey(): void
    {
        [$client, $fake] = $this->client();
        // The first attempt reaches catto-mail but its response is lost; the retry is a replay.
        $fake->failNext('POST /send-jobs', 'timeout', afterEffect: true);
        $fake->failNext('POST /send-jobs', '503');
        $job = $client->createSendJob('key-retry-0001', ['external_reference' => 'run/2', 'message_class' => 'transactional', 'sender_identity' => ['email' => 'a@ctnlist.test']]);
        self::assertCount(1, $fake->sendJobs, 'one job despite three attempts');
        $attempts = $fake->requestsTo('POST', '/send-jobs');
        self::assertCount(3, $attempts);
        self::assertSame(['key-retry-0001'], array_values(array_unique(array_map(static fn(array $r): string => $r['headers']['idempotency-key'], $attempts))));
        self::assertSame('collecting', $job['status']);

        $fake->failNext('GET /send-jobs/.*', 'timeout', times: 3);
        try {
            $client->getSendJob($job['id']);
            self::fail('no failure after three timeouts');
        } catch (CattoMailUnavailable $e) {
            self::assertStringContainsString('timeout', strtolower($e->getMessage()));
        }
    }

    public function testRejectionsCarryProblemDetailsAndNeverTheKey(): void
    {
        $key = 'shk_secret_key_never_logged';
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($key): MockResponse {
            return new MockResponse((string) json_encode(['type' => 'https://cattomail.test/problems/validation-error', 'title' => 'Invalid', 'status' => 422,
                'detail' => 'Rejected request with Authorization: Bearer ' . $key, 'errors' => [['pointer' => '/recipients/0/email_address', 'message' => 'bad ' . $key]]]),
                ['http_code' => 422, 'response_headers' => ['Content-Type' => 'application/problem+json']]);
        });
        $client = new CattoMailClient($http, new CattoMailConfig('https://cattomail.test', $key, ''), $this->logger());
        try {
            $client->createSendJob('key-0000-0001', ['external_reference' => 'x', 'message_class' => 'transactional', 'sender_identity' => ['email' => 'a@b.test']]);
            self::fail('accepted');
        } catch (CattoMailRejected $e) {
            self::assertSame(422, $e->status);
            self::assertStringEndsWith('/validation-error', $e->problemType);
            self::assertSame('/recipients/0/email_address', $e->errors[0]['pointer']);
            self::assertStringNotContainsString($key, $e->getMessage() . json_encode($e->errors));
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
        self::assertNotEmpty($this->logged);
        self::assertStringNotContainsString($key, implode("\n", $this->logged), 'not in the log either');

        $notFound = new CattoMailClient(new MockHttpClient(new MockResponse('{"status":404,"title":"Not found"}', ['http_code' => 404])), new CattoMailConfig('https://cattomail.test', $key, ''), $this->logger());
        $this->expectException(CattoMailRejected::class);
        $notFound->getSendJob('0199a000-0000-7000-8000-000000000001');
    }

    /** Webhook secrets, too, never reach a message or the log, even if catto-mail (or a proxy) echoed them. */
    public function testWebhookSecretsAreRedactedAndTransportFailuresClassified(): void
    {
        $secret = 'whsec_never_shown_anywhere';
        $http = new MockHttpClient(static fn(): MockResponse => new MockResponse('{"status":400,"detail":"bad signature ' . $secret . '"}', ['http_code' => 400]));
        $client = new CattoMailClient($http, new CattoMailConfig('https://cattomail.test', 'key', $secret), $this->logger());
        try {
            $client->getSendJob('0199a000-0000-7000-8000-000000000001');
            self::fail('accepted');
        } catch (CattoMailRejected $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
        self::assertStringNotContainsString($secret, implode("\n", $this->logged));

        self::assertSame('dns', CattoMailClient::transportFailure('Could not resolve host: catto-mail'));
        self::assertSame('tls', CattoMailClient::transportFailure('SSL certificate problem: unable to get local issuer certificate'));
        self::assertSame('connection', CattoMailClient::transportFailure('Failed to connect to localhost port 8443'));
        $down = new CattoMailClient(new MockHttpClient(static fn() => throw new \Symfony\Component\HttpClient\Exception\TransportException('Could not resolve host: catto-mail')),
            new CattoMailConfig('https://cattomail.test', 'key', ''), $this->logger(), static function (int $s): void {
            });
        try {
            $down->getSendJob('0199a000-0000-7000-8000-000000000001');
            self::fail('reached');
        } catch (CattoMailUnavailable $e) {
            self::assertStringStartsWith('catto-mail could not be reached (host name not found; the work is kept and retried)', $e->getMessage());
        }
    }

    /** The connect operations use their own API root and key: never the configured client's, and none for the exchange. */
    public function testConnectOperationsCarryTheirOwnKeyOrNone(): void
    {
        [$client, $fake] = $this->client();
        $back = $fake->approve(FakeCattoMail::ORIGIN . '/dashboard/connect?' . http_build_query(['application_name' => 'Lists', 'redirect_uri' => 'https://ctnlist.test/cb',
            'webhook_url' => 'https://ctnlist.test/cattomail/webhook', 'state' => 'abc', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 43), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256']));
        parse_str((string) parse_url($back, PHP_URL_QUERY), $answer);
        $code = is_string($answer['code'] ?? null) ? $answer['code'] : '';

        $credentials = $client->exchangeConnectCode(FakeCattoMail::ORIGIN, $code, str_repeat('v', 43), 'https://ctnlist.test/cb');
        self::assertSame(FakeCattoMail::BASE, $credentials['api_base_url']);
        self::assertSame(FakeCattoMail::CONNECT_EVENTS, $credentials['webhook']['events']);
        self::assertArrayNotHasKey('authorization', $fake->requests[0]['headers'], 'the exchange is the one request without an API key');

        // The same code again: refused, and neither the code nor the verifier is in the message.
        try {
            $client->exchangeConnectCode(FakeCattoMail::ORIGIN, $code, str_repeat('v', 43), 'https://ctnlist.test/cb');
            self::fail('a code worked twice');
        } catch (CattoMailRejected $e) {
            self::assertSame(400, $e->status);
            self::assertStringEndsWith('/invalid-connect-code', $e->problemType);
            self::assertStringNotContainsString($code, $e->getMessage());
            self::assertStringNotContainsString('rejected the API key', $e->getMessage(), 'no advice about the configured key');
        }

        // The key works like any other, lists the sending domains, and ends its own connection.
        $connected = new CattoMailClient(new MockHttpClient($fake->handler(), FakeCattoMail::BASE), new CattoMailConfig('https://cattomail.test', $credentials['api_key'], ''), $this->logger());
        self::assertSame(['domain' => 'ctnlist.test', 'status' => 'verified', 'dkim_status' => 'active', 'can_send' => true], array_diff_key($connected->listSendingDomains()[0], ['setup_url' => 1]));
        $client->deleteConnection(FakeCattoMail::BASE, $credentials['api_key']);
        self::assertSame('Bearer ' . $credentials['api_key'], $fake->requests[3]['headers']['authorization'], 'the connection\'s key, not the configured one');
        self::assertSame(401, $connected->probe()['status'], 'the key ended with its connection');
        try {
            $client->deleteConnection(FakeCattoMail::BASE, FakeCattoMail::KEY);
            self::fail('ended a connection that does not exist');
        } catch (CattoMailRejected $e) {
            self::assertSame(404, $e->status, 'a key that no connection issued');
        }
        self::assertSame([], array_filter($this->logged, static fn(string $line): bool => str_contains($line, $credentials['api_key']) || str_contains($line, $code)), 'no key and no code in the log');
    }

    /** The development route: another host name and, with host:port, another port; the request still names the API host. */
    public function testTheDevelopmentRouteMapsHostAndPort(): void
    {
        self::assertSame(['catto-mail', 443], CattoMailClient::connectTarget('catto-mail:443'));
        self::assertSame(['catto-mail', null], CattoMailClient::connectTarget(' catto-mail '));
        self::assertSame(['127.0.0.1', 8443], CattoMailClient::connectTarget('127.0.0.1:8443'));
        self::assertSame(['', null], CattoMailClient::connectTarget(''));

        /** @var \ArrayObject<int, array{url: string, resolve: mixed, headers: mixed}> $seen */
        $seen = new \ArrayObject();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($seen): MockResponse {
            $seen->append(['url' => $url, 'resolve' => $options['resolve'] ?? [], 'headers' => $options['headers'] ?? []]);
            return new MockResponse('{"type":"x/not-found","status":404}', ['http_code' => 404]);
        });
        $client = new CattoMailClient($http, new CattoMailConfig('https://localhost:8443', 'shk_x', '', connectHost: '127.0.0.9:443'), $this->logger());
        self::assertSame(404, $client->probe()['status']);
        $first = $seen->getArrayCopy()[0];
        self::assertStringStartsWith('https://localhost/v1/send-jobs/', $first['url'], 'port 443, where the service answers inside the development network, not 8443');
        self::assertSame(['localhost' => '127.0.0.9'], $first['resolve']);
        self::assertContains('Host: localhost:8443', $first['headers'], 'the API host as the base URL names it');

        // Without a port only the host is mapped, as before.
        $client = new CattoMailClient($http, new CattoMailConfig('https://localhost:8443', 'shk_x', '', connectHost: '127.0.0.9'), $this->logger());
        $client->probe();
        $second = $seen->getArrayCopy()[1];
        self::assertStringStartsWith('https://localhost:8443/v1/send-jobs/', $second['url']);
        self::assertSame(['localhost' => '127.0.0.9'], $second['resolve']);
    }

    public function testConfigurationAndInputGuards(): void
    {
        $client = new CattoMailClient(new MockHttpClient(), new CattoMailConfig('', '', ''), $this->logger());
        try {
            $client->getSendJob('0199a000-0000-7000-8000-000000000001');
            self::fail('not configured');
        } catch (CattoMailRejected $e) {
            self::assertStringContainsString('not configured', $e->getMessage());
        }
        $http = new CattoMailClient(new MockHttpClient(), new CattoMailConfig('http://cattomail.test', 'k', ''), $this->logger());
        try {
            $http->getSendJob('0199a000-0000-7000-8000-000000000001');
            self::fail('plain http');
        } catch (CattoMailRejected $e) {
            self::assertStringContainsString('https', $e->getMessage());
        }
        [$client] = $this->client();
        $this->expectException(\InvalidArgumentException::class);
        $client->getSendJob('../../admin');
    }

    /** @return array{0: CattoMailClient, 1: FakeCattoMail} */
    private function client(): array
    {
        $fake = new FakeCattoMail();
        $client = new CattoMailClient(new MockHttpClient($fake->handler(), FakeCattoMail::BASE),
            new CattoMailConfig('https://cattomail.test', FakeCattoMail::KEY, 'whsec_x'), $this->logger(), static function (int $s): void {
            });
        return [$client, $fake];
    }

    private function logger(): AbstractLogger
    {
        $test = $this;
        return new class ($test) extends AbstractLogger {
            public function __construct(private readonly CattoMailClientTest $test)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->test->record($message . ' ' . json_encode($context));
            }
        };
    }

    public function record(string $line): void
    {
        $this->logged[] = $line;
    }

    /** @return array<string, string> */
    private static function recipient(string $reference, string $email): array
    {
        return ['external_recipient_reference' => $reference, 'email_address' => $email, 'subject' => 'Hello', 'html_body' => '<p>Hello</p>',
            'text_body' => 'Hello', 'unsubscribe_url' => 'https://ctnlist.test/unsubscribe-link/x'];
    }
}
