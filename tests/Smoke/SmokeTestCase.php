<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that drive the running podman stack over HTTP (from the app
 * container to SMOKE_BASE_URL) and inspect the development database. They
 * exercise the web server, not the framework, so they stay valid throughout
 * the Fat-Free to Symfony migration.
 */
abstract class SmokeTestCase extends TestCase
{
    protected static PDO $db;
    protected static string $baseUrl;
    /** Start of the test class, in the application's (PHP) timezone. */
    protected static string $startedAt;
    /** @var array{s_id: int, s_uuid: string, s_email: string} */
    protected static array $admin;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim((string) getenv('SMOKE_BASE_URL'), '/');
        self::$db = new PDO(
            (string) getenv('SMOKE_DB_DSN'),
            (string) getenv('SMOKE_DB_USER'),
            (string) getenv('SMOKE_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // The application writes timestamps in PHP's timezone, not the database's.
        self::$startedAt = date('Y-m-d H:i:s');

        $admin = self::$db->prepare('SELECT s_id, s_uuid, s_email FROM subscribers WHERE LOWER(s_email) = LOWER(?)');
        $admin->execute([(string) getenv('SMOKE_ADMIN_EMAIL')]);
        $row = $admin->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            self::fail('Development administrator not found; run bin/dev seed-admin.');
        }
        self::$admin = ['s_id' => (int) $row['s_id'], 's_uuid' => (string) $row['s_uuid'], 's_email' => (string) $row['s_email']];
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')
            ->execute([self::$admin['s_id'], self::$startedAt]);
        self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')
            ->execute([self::$admin['s_id'], self::$startedAt]);
    }

    /** A browser-like client: one curl handle with its own cookie jar. */
    protected static function client(): \CurlHandle
    {
        $client = curl_init();
        curl_setopt($client, CURLOPT_COOKIEFILE, '');
        return $client;
    }

    /** Store a magic-link token directly (as POST /login would) and return the raw token. */
    protected static function issueLoginToken(int $subscriberId, string $returnAction = 'profile'): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::$db->prepare(
            'INSERT INTO auth_login_tokens (alt_s_id, alt_email, alt_token_hash, alt_created_at, alt_expires_at, alt_return_action)
             SELECT s_id, s_email, ?, ?, ?, ? FROM subscribers WHERE s_id = ?'
        )->execute([hash('sha256', $token), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 600), $returnAction, $subscriberId]);
        return $token;
    }

    /** Sign the client in as the development administrator. */
    protected static function loginAsAdmin(\CurlHandle $client): void
    {
        $response = self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken(self::$admin['s_id']));
        if ($response['status'] !== 302 || str_ends_with($response['location'], '/login')) {
            self::fail("Administrator magic-link login failed (HTTP {$response['status']}, Location {$response['location']}).");
        }
    }

    /**
     * @param array<string, string> $fields form fields for POST
     * @return array{status: int, body: string, location: string, cookies: list<string>}
     */
    protected static function request(\CurlHandle $client, string $method, string $path, array $fields = []): array
    {
        $location = '';
        $cookies = [];
        curl_setopt_array($client, [
            CURLOPT_URL => self::$baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($fields) : null,
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location, &$cookies): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                } elseif (stripos($header, 'Set-Cookie:') === 0) {
                    $cookies[] = trim(substr($header, 11));
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($client);
        if ($body === false) {
            self::fail("{$method} {$path} failed: " . curl_error($client));
        }
        return [
            'status' => (int) curl_getinfo($client, CURLINFO_HTTP_CODE),
            'body' => (string) $body,
            'location' => $location,
            'cookies' => $cookies,
        ];
    }

    /** No PHP error output and no unresolved Fat-Free template tokens. */
    protected static function assertCleanPage(string $route, string $body): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>|Uncaught |Stack trace:/',
            $body,
            "PHP error output on {$route}"
        );
        self::assertStringNotContainsString('{{@', $body, "unresolved F3 token on {$route}");
    }
}
