<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\CattoMailClient;
use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailConfigFactory;
use App\CattoMail\CattoMailHealth;
use App\CattoMail\ConnectFlow;
use App\CattoMail\ConnectionStore;
use App\CattoMail\SendingDomainCheck;
use App\CattoMail\WebhookSignature;
use App\Config\SettingsCipher;
use App\Config\SiteConfig;
use App\Repository\OptionRepository;
use App\Repository\SettingRepository;
use App\Security\SubscriberUser;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\FakeCattoMail;
use App\Validator\InvalidField;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Connecting and disconnecting the mail service (ConnectFlow) against
 * FakeCattoMail's connect page, exchange, connection and sending-domains
 * operations: what is sent and stored when it works, every way it is
 * refused, and that a key or secret is only ever stored encrypted. The
 * installation's .env sets no catto-mail here, so the connection is the only
 * source of the address, the key and the secret.
 */
final class ConnectFlowTest extends IntegrationTestCase
{
    private const ENV = ['CATTOMAIL_API_BASE_URL', 'CATTOMAIL_API_KEY', 'CATTOMAIL_WEBHOOK_SECRET', 'CATTOMAIL_WEBHOOK_SECRET_PREVIOUS', 'CATTOMAIL_WEBHOOK_URL', 'CATTOMAIL_API_CONNECT_HOST'];
    private const CALLBACK = 'https://ctnlist.test/delivery/connect/callback';

    private FakeCattoMail $fake;
    private ConnectFlow $flow;
    private SubscriberUser $admin;
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
            $_ENV[$name] = $_SERVER[$name] = '';
            putenv($name . '=');
        }
        parent::setUp();
        $this->fake = $this->fakeCattoMail();
        $this->flow = $this->service(ConnectFlow::class);
        $this->admin = $this->administrator('connect-admin@ctnlist.test');
        // The site's From address is on a domain the mail service knows and can send from.
        $this->fake->senderDomains = [$this->fromDomain(), 'smarthost-dev.test'];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
                putenv($name . '=' . $value);
            }
        }
    }

    public function testConnectingStoresWhatTheMailServiceHandsOverOnce(): void
    {
        self::assertNull($this->flow->obstacle());
        self::assertFalse($this->config()->isConfigured());
        self::assertStringContainsString('connect the mail service on the Delivery page', (string) $this->config()->problem());

        // 1. The administrator names the mail service (any of its pages will do); the browser is sent to its connect page.
        $address = $this->flow->start('https://CattoMail.test/dashboard/login?next=1', self::CALLBACK, $this->admin);
        self::assertStringStartsWith(FakeCattoMail::ORIGIN . '/dashboard/connect?', $address);
        $sent = self::query($address);
        self::assertSame($this->service(SiteConfig::class)->listName, $sent['application_name']);
        self::assertSame(self::CALLBACK, $sent['redirect_uri']);
        self::assertSame('https://ctnlist.test/cattomail/webhook', $sent['webhook_url']);
        self::assertSame('S256', $sent['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $sent['state']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $sent['code_challenge']);
        self::assertSame(['application_name', 'redirect_uri', 'webhook_url', 'state', 'code_challenge', 'code_challenge_method'], array_keys($sent), 'nothing else, and no verifier');
        // What waits here: the state, and the verifier only encrypted.
        $pending = json_decode((string) $this->service(OptionRepository::class)->get('cattomail:connect:pending'), true);
        self::assertIsArray($pending);
        self::assertSame($sent['state'], $pending['state']);
        self::assertStringStartsWith('enc:v1:', $pending['verifier']);
        self::assertSame($sent['code_challenge'], ConnectFlow::challenge($this->service(SettingsCipher::class)->decrypt($pending['verifier'])));
        self::assertSame([], $this->fake->requests, 'nothing is asked of the mail service before the approval');

        // 2. A client admin approves there; the browser comes back with a code and the state.
        $back = self::query($this->fake->approve($address));
        self::assertSame($sent['state'], $back['state']);
        self::assertTrue($this->flow->expects($back['state'], $this->admin));

        // 3. The code is exchanged from this server, without an API key, with the verifier.
        $connected = $this->flow->complete($back['state'], $back['code'], $this->admin);
        self::assertSame(['service' => FakeCattoMail::ORIGIN, 'client_name' => 'Example Ltd', 'application_name' => $sent['application_name']], $connected);
        self::assertCount(1, $this->fake->requests);
        $exchange = $this->fake->requests[0];
        self::assertSame(['POST', FakeCattoMail::ORIGIN . '/v1/connect/exchange'], [$exchange['method'], $exchange['url']]);
        self::assertArrayNotHasKey('authorization', $exchange['headers']);
        self::assertSame(['code', 'code_verifier', 'redirect_uri'], array_keys($exchange['body']));
        self::assertSame($sent['code_challenge'], ConnectFlow::challenge($exchange['body']['code_verifier']));
        self::assertSame(self::CALLBACK, $exchange['body']['redirect_uri']);

        // The key and the secret are in the Settings store, encrypted; nothing else holds them.
        $issued = $this->fake->connections[$this->fake->lastConnectionId()];
        $stored = $this->service(SettingRepository::class)->all();
        self::assertSame(FakeCattoMail::BASE, $stored['CATTOMAIL_API_BASE_URL']['value']);
        foreach (['CATTOMAIL_API_KEY' => $issued['api_key'], 'CATTOMAIL_WEBHOOK_SECRET' => $issued['signing_secret']] as $name => $secret) {
            self::assertTrue($stored[$name]['secret'], $name);
            self::assertStringStartsWith('enc:v1:', $stored[$name]['value']);
            self::assertSame($secret, $this->service(SettingsCipher::class)->decrypt($stored[$name]['value']));
            self::assertSame($this->admin->email, $stored[$name]['updated_by']);
        }
        $everything = (string) json_encode($this->db->fetchAllAssociative('SELECT o_key, o_value FROM options'));
        self::assertStringNotContainsString((string) $issued['api_key'], $everything, 'the key is nowhere in plain text');
        self::assertStringNotContainsString((string) $issued['signing_secret'], $everything, 'nor is the signing secret');
        self::assertNull($this->service(OptionRepository::class)->get('cattomail:connect:pending'), 'the request is finished');
        self::assertNull($this->flow->pending());
        $details = $this->service(ConnectionStore::class)->details();
        self::assertNotNull($details);
        self::assertSame([$issued['id'], 'Example Ltd', FakeCattoMail::ORIGIN, 'https://ctnlist.test/cattomail/webhook', $issued['endpoint_id'], $this->admin->email],
            [$details['connection_id'], $details['client_name'], $details['service'], $details['webhook_url'], $details['webhook_endpoint_id'], $details['connected_by']]);
        self::assertNotNull($this->flow->changedAt());

        // From the next request on the integration works with it: the API, and the webhooks' signatures.
        $config = $this->config();
        self::assertTrue($config->isConfigured());
        self::assertTrue($config->usesConnection());
        self::assertSame([], $config->envSettings());
        self::assertSame($issued['api_key'], $config->apiKey());
        self::assertSame([$issued['signing_secret']], $config->webhookSecrets());
        self::assertSame(['status' => 404, 'failure' => null, 'error' => null], $this->client($config)->probe(), 'the key is accepted');
        $now = $this->service(ClockInterface::class)->now()->getTimestamp();
        $signature = new WebhookSignature($config, $this->service(ClockInterface::class));
        self::assertSame(WebhookSignature::VALID, $signature->verify('{"id":"1"}', 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.{"id":"1"}', (string) $issued['signing_secret'])));
        self::assertSame(WebhookSignature::MISMATCH, $signature->verify('{"id":"1"}', 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.{"id":"1"}', 'whsec_test_current')));
        // The exchange said which domains can send: the site's From domain is one of them.
        self::assertSame(SendingDomainCheck::OK, $this->service(SendingDomainCheck::class)->fromAddress()['state']);
    }

    public function testAnApprovalIsRefusedWhenItIsNotThisRequestsOrItsCodeIsNotAccepted(): void
    {
        $address = $this->flow->start(FakeCattoMail::ORIGIN, self::CALLBACK, $this->admin);
        $back = self::query($this->fake->approve($address));
        $state = $back['state'];
        $code = $back['code'];

        // Another state (a forged or stale answer), and another administrator's browser.
        self::assertFalse($this->flow->expects('x' . substr($state, 1), $this->admin));
        $this->assertRefused('does not belong to the connection request', fn() => $this->flow->complete('x' . substr($state, 1), $code, $this->admin));
        $other = $this->administrator('other-admin@ctnlist.test');
        self::assertFalse($this->flow->expects($state, $other));
        $this->assertRefused('does not belong to the connection request', fn() => $this->flow->complete($state, $code, $other));
        $this->assertRefused('no usable code', fn() => $this->flow->complete($state, '', $this->admin));
        self::assertSame([], $this->fake->requests, 'none of these reach the mail service');
        self::assertNotNull($this->flow->pending(), 'and the request still waits');

        // A code the mail service does not accept: not its code, then (after its 10 minutes) the right one.
        $this->assertRefused('did not accept the approval', fn() => $this->flow->complete($state, 'not-the-code', $this->admin));
        self::assertNull($this->flow->pending(), 'only a new request helps');
        $this->assertRefused('No connection request is waiting', fn() => $this->flow->complete($state, $code, $this->admin));

        $address = $this->flow->start(FakeCattoMail::ORIGIN, self::CALLBACK, $this->admin);
        $back = self::query($this->fake->approve($address));
        $this->fake->expireCodes();
        $this->assertRefused('did not accept the approval', fn() => $this->flow->complete($back['state'], $back['code'], $this->admin));

        // A code works once: the second use is refused there, and nothing here waits for it anyway.
        $address = $this->flow->start(FakeCattoMail::ORIGIN, self::CALLBACK, $this->admin);
        $back = self::query($this->fake->approve($address));
        $this->flow->complete($back['state'], $back['code'], $this->admin);
        $this->assertRefused('No connection request is waiting', fn() => $this->flow->complete($back['state'], $back['code'], $this->admin));
        $verifier = $this->fake->requestsTo('POST', '/connect/exchange')[2]['body']['code_verifier'];
        $this->assertRejected(400, 'invalid-connect-code', fn() => $this->client($this->config())->exchangeConnectCode(FakeCattoMail::ORIGIN, $back['code'], $verifier, self::CALLBACK));
        // A wrong verifier or another redirect URI gets the same answer as a wrong code.
        $address = $this->flow->start(FakeCattoMail::ORIGIN, self::CALLBACK, $this->admin);
        $fresh = self::query($this->fake->approve($address));
        $this->assertRejected(400, 'invalid-connect-code', fn() => $this->client($this->config())->exchangeConnectCode(FakeCattoMail::ORIGIN, $fresh['code'], str_repeat('a', 43), self::CALLBACK));
        $this->assertRejected(400, 'invalid-connect-code', fn() => $this->client($this->config())->exchangeConnectCode(FakeCattoMail::ORIGIN, $fresh['code'], $verifier, 'https://elsewhere.example/callback'));
    }

    public function testNothingIsStoredWhenTheMailServiceRefusesOrCannotBeReached(): void
    {
        $address = $this->flow->start(FakeCattoMail::ORIGIN, self::CALLBACK, $this->admin);
        $back = self::query($this->fake->approve($address));
        $state = $back['state'];
        $code = $back['code'];

        // The client is at its API key limit: 409, nothing created there, nothing stored here, and the code stays usable.
        $this->fake->refuseExchange = 'The client has reached its limit of 2 API keys.';
        $this->assertRefused('reached its limit of 2 API keys', fn() => $this->flow->complete($state, $code, $this->admin));
        self::assertNull($this->service(ConnectionStore::class)->credentials());
        self::assertTrue($this->flow->expects($state, $this->admin), 'Finish can be pressed again');

        // The mail service does not answer (every attempt): the same.
        $this->fake->refuseExchange = null;
        $this->fake->failNext('POST /connect/exchange', 'timeout', times: 3);
        $this->assertRefused('could not be reached', fn() => $this->flow->complete($state, $code, $this->admin));
        self::assertNull($this->service(ConnectionStore::class)->credentials());
        self::assertTrue($this->flow->expects($state, $this->admin));

        // Then it works, with the same approval.
        $this->flow->complete($state, $code, $this->admin);
        self::assertTrue($this->config()->usesConnection());
    }

    public function testADeclinedRequestChangesNothing(): void
    {
        $address = $this->flow->start(FakeCattoMail::ORIGIN, self::CALLBACK, $this->admin);
        $back = self::query($this->fake->decline($address));
        self::assertSame('access_denied', $back['error']);
        self::assertArrayNotHasKey('code', $back);
        self::assertTrue($this->flow->expects($back['state'], $this->admin), 'the answer is this request\'s');
        self::assertSame([], $this->fake->requests);
        self::assertNull($this->service(ConnectionStore::class)->credentials());
    }

    public function testOnlyAnHttpsMailServiceAddressIsAccepted(): void
    {
        self::assertSame('https://mail.example.org', ConnectFlow::serviceOrigin(' mail.example.org '));
        self::assertSame('https://mail.example.org:8443', ConnectFlow::serviceOrigin('https://Mail.Example.org:8443/dashboard/c/1?x=1#top'));
        foreach (['', 'http://mail.example.org', 'ftp://mail.example.org', 'https://user:secret@mail.example.org', 'https://mail example.org', 'https://'] as $address) {
            try {
                ConnectFlow::serviceOrigin($address);
                self::fail('accepted: ' . $address);
            } catch (InvalidField $e) {
                self::assertSame('service', $e->field, $address);
            }
        }
        $this->assertRefused('must start with https://', fn() => $this->flow->start('http://cattomail.test', self::CALLBACK, $this->admin));
        self::assertNull($this->flow->pending(), 'nothing was started');

        // Outside development this site must be on https too: the mail service refuses anything else.
        $production = $this->flowWith($this->config(), 'prod');
        $this->assertRefused('only to an https address', fn() => $production->start(FakeCattoMail::ORIGIN, 'http://ctnlist.test/delivery/connect/callback', $this->admin));
        // In development catto-mail accepts plain http, and a webhook address it can reach.
        $development = $this->flowWith(new CattoMailConfig('', '', '', webhookUrl: 'http://ctnlist-web/cattomail/webhook'), 'dev');
        $sent = self::query($development->start(FakeCattoMail::ORIGIN, 'http://localhost:8180/delivery/connect/callback', $this->admin));
        self::assertSame(['http://localhost:8180/delivery/connect/callback', 'http://ctnlist-web/cattomail/webhook'], [$sent['redirect_uri'], $sent['webhook_url']]);
    }

    public function testDisconnectingRevokesTheConnectionThenClearsWhatIsStored(): void
    {
        $issued = $this->connect();
        $config = $this->config();
        $this->service(CattoMailHealth::class);
        $this->fake->requests = [];

        $this->flow->disconnect($this->admin);

        self::assertCount(1, $this->fake->requests);
        $request = $this->fake->requests[0];
        self::assertSame(['DELETE', FakeCattoMail::BASE . '/connection'], [$request['method'], $request['url']]);
        self::assertSame('Bearer ' . $issued['api_key'], $request['headers']['authorization'], 'the connection ends itself with its own key');
        self::assertSame('revoked', $this->fake->connections[$issued['id']]['status']);
        // Nothing of it is left here.
        self::assertNull($this->service(ConnectionStore::class)->credentials());
        self::assertNull($this->service(ConnectionStore::class)->details());
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM options WHERE o_key LIKE 'setting:CATTOMAIL_%'"));
        self::assertNull($this->service(SendingDomainCheck::class)->last());
        self::assertFalse($this->config()->isConfigured());
        // And the key is no longer worth anything at the mail service.
        self::assertSame(401, $this->client($config)->probe()['status']);
        $this->assertRefused('No connection is stored', fn() => $this->flow->disconnect($this->admin));
    }

    public function testAConnectionIsKeptWhenTheMailServiceDidNotEndIt(): void
    {
        $issued = $this->connect();

        // The mail service does not answer: nothing changes here, so Disconnect can be tried again.
        $this->fake->failNext('DELETE /connection', 'timeout', times: 3);
        $this->assertRefused('could not be reached', fn() => $this->flow->disconnect($this->admin));
        self::assertTrue($this->config()->usesConnection());
        self::assertSame('active', $this->fake->connections[$issued['id']]['status']);

        // Forgetting clears what is stored without asking the mail service: the key stays valid there.
        $asked = count($this->fake->requests);
        $this->flow->disconnect($this->admin, forget: true);
        self::assertCount($asked, $this->fake->requests, 'the mail service was not asked');
        self::assertNull($this->service(ConnectionStore::class)->credentials());
        self::assertSame('active', $this->fake->connections[$issued['id']]['status']);

        // Connecting again from the same site replaces that earlier connection at the mail service.
        $again = $this->connect();
        self::assertSame('revoked', $this->fake->connections[$issued['id']]['status']);
        self::assertNotSame($issued['api_key'], $again['api_key']);

        // Ended in the mail service's dashboard meanwhile: its 401 means there is nothing left to end.
        $this->fake->revokeConnection($again['id']);
        $this->flow->disconnect($this->admin);
        self::assertNull($this->service(ConnectionStore::class)->credentials());

        // A key that no connection issued cannot be ended this way (404): it is kept.
        $this->service(ConnectionStore::class)->save(FakeCattoMail::BASE, FakeCattoMail::KEY, 'whsec_x', ['connection_id' => '', 'application_name' => '', 'client_name' => '',
            'service' => FakeCattoMail::ORIGIN, 'webhook_url' => '', 'webhook_endpoint_id' => ''], $this->admin->id);
        $this->assertRefused('did not end the connection', fn() => $this->flow->disconnect($this->admin));
        self::assertNotNull($this->service(ConnectionStore::class)->credentials());
    }

    public function testEnvWinsOverTheConnectionAndConnectIsThenRefused(): void
    {
        $issued = $this->connect();

        // Nothing in .env: the connection supplies all three.
        $config = $this->config();
        self::assertSame([CattoMailConfig::SOURCE_CONNECTION, CattoMailConfig::SOURCE_CONNECTION, CattoMailConfig::SOURCE_CONNECTION],
            [$config->source('base_url'), $config->source('api_key'), $config->source('webhook_secret')]);

        // .env sets the address and the key: those win; the connection still supplies the secret.
        $mixed = $this->config(['https://env.example', 'shk_from_env', '']);
        self::assertSame(['https://env.example/v1', 'shk_from_env', [$issued['signing_secret']]], [$mixed->apiRoot(), $mixed->apiKey(), $mixed->webhookSecrets()]);
        self::assertFalse($mixed->usesConnection());
        self::assertSame(['CATTOMAIL_API_BASE_URL', 'CATTOMAIL_API_KEY'], $mixed->envSettings());
        // .env sets all of it: nothing of the connection is used.
        $env = $this->config(['https://env.example', 'shk_from_env', 'whsec_from_env']);
        self::assertSame(['shk_from_env', ['whsec_from_env']], [$env->apiKey(), $env->webhookSecrets()]);
        self::assertSame(['CATTOMAIL_API_BASE_URL', 'CATTOMAIL_API_KEY', 'CATTOMAIL_WEBHOOK_SECRET'], $env->envSettings());

        // A connection made while .env sets them would not be used, so it is not started.
        $flow = $this->flowWith($mixed, 'test');
        self::assertStringContainsString('CATTOMAIL_API_BASE_URL, CATTOMAIL_API_KEY', (string) $flow->obstacle());
        $this->assertRefused('takes precedence', fn() => $flow->start(FakeCattoMail::ORIGIN, self::CALLBACK, $this->admin));
    }

    public function testAStoredKeyThatCannotBeReadIsReportedNotUsed(): void
    {
        $issued = $this->connect();
        // The installation's APP_SETTINGS_KEY was replaced after the connection was made.
        $otherKey = new SettingsCipher(base64_encode(random_bytes(32)));
        $store = new ConnectionStore($this->service(SettingRepository::class), $otherKey, $this->db, new NullLogger());
        $config = $this->config([], $store);

        self::assertFalse($config->isConfigured());
        self::assertSame('', $config->apiKey());
        self::assertStringContainsString('different APP_SETTINGS_KEY', $config->connectionProblem);
        self::assertStringContainsString('the stored connection cannot be read', (string) $config->problem());
        // It cannot be ended at the mail service from here, only forgotten.
        $flow = $this->flowWith($config, 'test', $store);
        $this->assertRefused('cannot be read', fn() => $flow->disconnect($this->admin));
        $flow->disconnect($this->admin, forget: true);
        self::assertNull($store->details());
        self::assertSame('active', $this->fake->connections[$issued['id']]['status']);
    }

    public function testTheFromDomainIsCheckedAgainstTheMailServicesSendingDomains(): void
    {
        $domain = $this->fromDomain();
        $check = $this->service(SendingDomainCheck::class);
        self::assertSame(['state' => SendingDomainCheck::UNCHECKED, 'domain' => $domain], array_intersect_key($check->fromAddress(), ['state' => 1, 'domain' => 1]));

        // The connection's answer names the domains; a domain that is registered but not verified cannot send.
        $this->fake->domainStates[$domain] = ['status' => 'pending', 'dkim_status' => 'pending_dns', 'can_send' => false];
        $this->connect();
        self::assertSame([SendingDomainCheck::CANNOT_SEND, 'pending', 'pending_dns'], array_values(array_intersect_key($check->fromAddress(), ['state' => 1, 'status' => 1, 'dkim_status' => 1])));

        // "Check connection" asks again (GET /v1/sending-domains) with the connection's key.
        $config = $this->config();
        $client = $this->client($config);
        $live = new SendingDomainCheck($client, $this->service(OptionRepository::class), $this->service(SiteConfig::class), $this->service(ClockInterface::class));
        $health = new CattoMailHealth($client, $this->service(OptionRepository::class), $this->service(ClockInterface::class), $live);
        unset($this->fake->domainStates[$domain]);
        self::assertSame(CattoMailHealth::OK, $health->check()['state']);
        self::assertSame(SendingDomainCheck::OK, $check->fromAddress()['state']);
        self::assertSame(['domain' => $domain, 'status' => 'verified', 'dkim_status' => 'active', 'can_send' => true], $client->listSendingDomains()[0]);

        // The From domain is not one of the client's sending domains at all.
        $this->fake->senderDomains = ['smarthost-dev.test'];
        $health->check();
        self::assertSame(SendingDomainCheck::NOT_REGISTERED, $check->fromAddress()['state']);

        // A check that fails keeps the last answer.
        $this->fake->senderDomains = [$domain];
        $this->fake->failNext('GET /sending-domains', '503', times: 3);
        self::assertSame(CattoMailHealth::OK, $health->check()['state']);
        self::assertSame(SendingDomainCheck::NOT_REGISTERED, $check->fromAddress()['state']);
    }

    // ---- helpers ---------------------------------------------------------------

    /**
     * Connect through the whole flow; returns the fake's connection (its key and secret included).
     *
     * @return array<string, mixed>
     */
    private function connect(): array
    {
        $address = $this->flow->start(FakeCattoMail::ORIGIN, self::CALLBACK, $this->admin);
        $back = self::query($this->fake->approve($address));
        $this->flow->complete($back['state'], $back['code'], $this->admin);
        return $this->fake->connections[$this->fake->lastConnectionId()];
    }

    /**
     * The configuration as the next request builds it: .env ($env: address, key, secret) and the stored connection.
     *
     * @param array{0?: string, 1?: string, 2?: string} $env
     */
    private function config(array $env = [], ?ConnectionStore $store = null): CattoMailConfig
    {
        return (new CattoMailConfigFactory($env[0] ?? '', $env[1] ?? '', $env[2] ?? '', null, null, null, null, null, null, null, null, 'test', null, null, null,
            $store ?? $this->service(ConnectionStore::class)))();
    }

    private function client(CattoMailConfig $config): CattoMailClient
    {
        return new CattoMailClient(new MockHttpClient($this->fake->handler(), FakeCattoMail::BASE), $config, new NullLogger(), static function (int $seconds): void {
        });
    }

    private function flowWith(CattoMailConfig $config, string $environment, ?ConnectionStore $store = null): ConnectFlow
    {
        return new ConnectFlow($this->service(CattoMailClient::class), $config, $store ?? $this->service(ConnectionStore::class), $this->service(SendingDomainCheck::class),
            $this->service(OptionRepository::class), $this->service(SettingsCipher::class), $this->service(SiteConfig::class), $this->service(ClockInterface::class), new NullLogger(), $environment);
    }

    /**
     * The query of an address the browser is sent to, as its named values.
     *
     * @return array<string, string>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return array_map(static fn(mixed $value): string => is_string($value) ? $value : '', array_filter($query, 'is_string', ARRAY_FILTER_USE_KEY));
    }

    private function administrator(string $email): SubscriberUser
    {
        $id = $this->createSubscriber($email, 'Connect', 'Admin');
        return new SubscriberUser($id, (string) $this->db->fetchOne('SELECT s_uuid FROM subscribers WHERE s_id = ?', [$id]), $email, 'Connect', 'Admin', ['administrator', 'subscriber'], []);
    }

    private function fromDomain(): string
    {
        return strtolower(substr((string) strrchr($this->service(SiteConfig::class)->fromAddress, '@'), 1));
    }

    private function assertRefused(string $expected, \Closure $action): void
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
            self::assertDoesNotMatchRegularExpression('/shk_connect_|whsec_connect_/', $e->getMessage(), 'a refusal never shows a key or secret');
            return;
        }
        self::fail('Not refused; expected: ' . $expected);
    }

    private function assertRejected(int $status, string $type, \Closure $action): void
    {
        try {
            $action();
        } catch (\App\CattoMail\CattoMailRejected $e) {
            self::assertSame($status, $e->status);
            self::assertStringEndsWith('/' . $type, $e->problemType);
            return;
        }
        self::fail('Not rejected with ' . $type);
    }
}
