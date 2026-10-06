<?php

declare(strict_types=1);

namespace App\Tests\Integration\CattoMail;

use App\CattoMail\CattoMailClient;
use App\CattoMail\CattoMailConfig;
use App\CattoMail\CattoMailHealth;
use App\Repository\OptionRepository;
use App\Tests\Integration\IntegrationTestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The connection check: one harmless authenticated read, classified for the administrator. */
final class CattoMailHealthTest extends IntegrationTestCase
{
    private const KEY = 'ctm_live_supersecret_key_value';

    public function testNotConfigured(): void
    {
        $result = $this->health(new CattoMailConfig('', '', ''), static fn() => self::fail('no request without configuration'))->check();
        self::assertSame('not_configured', $result['state']);
    }

    /** @return iterable<string, array{\Closure, string}> */
    public static function outcomes(): iterable
    {
        yield 'authenticated (404 for the impossible job)' => [static fn() => new MockResponse('{"status":404}', ['http_code' => 404]), 'ok'];
        yield 'key refused' => [static fn() => new MockResponse('{"status":401,"detail":"Bearer ' . self::KEY . '"}', ['http_code' => 401]), 'auth_rejected'];
        yield 'client not permitted' => [static fn() => new MockResponse('', ['http_code' => 403]), 'forbidden'];
        yield 'rate limited' => [static fn() => new MockResponse('', ['http_code' => 429]), 'unavailable'];
        yield 'server error' => [static fn() => new MockResponse('', ['http_code' => 503]), 'unavailable'];
        yield 'unexpected success' => [static fn() => new MockResponse('{}', ['http_code' => 200]), 'unexpected'];
        yield 'dns' => [static fn() => throw new TransportException('Could not resolve host: catto-mail.invalid'), 'dns'];
        yield 'tls' => [static fn() => throw new TransportException('SSL certificate problem: self-signed certificate'), 'tls'];
        yield 'refused' => [static fn() => throw new TransportException('Failed to connect to localhost port 443: Connection refused'), 'connection'];
        yield 'timeout' => [static fn() => throw new TransportException('Operation timed out after 15000 milliseconds'), 'connection'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function testOutcomesAreClassified(\Closure $answer, string $state): void
    {
        $requests = [];
        $health = $this->health(new CattoMailConfig('https://mail.example.com', self::KEY, 'whsec_x'), static function (string $method, string $url, array $options) use ($answer, &$requests) {
            $requests[] = [$method, $url];
            return $answer();
        });
        $result = $health->check();
        self::assertSame($state, $result['state']);
        self::assertSame([['GET', 'https://mail.example.com/v1/send-jobs/' . CattoMailClient::PROBE_ID]], $requests, 'one read, nothing created');
        self::assertStringNotContainsString(self::KEY, $result['detail'], 'redacted');
        self::assertSame($result, $health->last(), 'kept for the status page');
    }

    /** @param \Closure(string, string, array<string, mixed>): MockResponse $handler */
    private function health(CattoMailConfig $config, \Closure $handler): CattoMailHealth
    {
        $client = new CattoMailClient(new MockHttpClient($handler), $config, new NullLogger(), static function (int $s): void {
        });
        return new CattoMailHealth($client, $this->service(OptionRepository::class), $this->service(ClockInterface::class));
    }
}
