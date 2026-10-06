<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Passwordless authentication, logout and CSRF behaviour of the running stack.
 */
final class AuthFlowTest extends SmokeTestCase
{
    private const AUTH_COOKIE = 'ctnlist_session';

    public function testMagicLinkSignsInOnceAndReturnsToTheProfile(): void
    {
        $client = self::client();
        $token = self::issueLoginToken(self::$admin['s_id']);

        $response = self::request($client, 'GET', '/auth/verify?token=' . $token);
        self::assertSame(302, $response['status']);
        self::assertSame('/profile/subscriber/' . self::$admin['s_uuid'], $response['location']);
        self::assertTrue(self::setsCookie($response['cookies'], self::AUTH_COOKIE), 'auth cookie set');
        self::assertSame(200, self::request($client, 'GET', '/lists')['status'], 'signed in as administrator');

        $reuse = self::request(self::client(), 'GET', '/auth/verify?token=' . $token);
        self::assertSame(302, $reuse['status']);
        self::assertStringEndsWith('/login', $reuse['location'], 'a link can only be used once');
    }

    public function testMagicLinkReturnsToTheRequestedAction(): void
    {
        $response = self::request(self::client(), 'GET', '/auth/verify?token=' . self::issueLoginToken(self::$admin['s_id'], 'messages'));
        self::assertSame(302, $response['status']);
        self::assertSame('/my/messages', $response['location']);
    }

    public function testInvalidMagicLinkRedirectsToLogin(): void
    {
        foreach (['', 'short', str_repeat('A', 43)] as $token) {
            $response = self::request(self::client(), 'GET', '/auth/verify?token=' . $token);
            self::assertSame(302, $response['status'], "token '{$token}'");
            self::assertStringEndsWith('/login', $response['location'], "token '{$token}'");
            self::assertFalse(self::setsCookie($response['cookies'], self::AUTH_COOKIE), "token '{$token}'");
        }
    }

    public function testUnknownAuthCookieIsIgnoredAndCleared(): void
    {
        $client = self::client();
        curl_setopt($client, CURLOPT_COOKIE, self::AUTH_COOKIE . '=' . str_repeat('B', 43));
        $response = self::request($client, 'GET', '/lists');
        self::assertSame(403, $response['status']);
        self::assertTrue(self::clearsCookie($response['cookies'], self::AUTH_COOKIE), 'stale auth cookie cleared');
    }

    public function testLogoutRevokesTheAuthSession(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $cookie = self::cookieValue($client, self::AUTH_COOKIE);
        self::assertNotNull($cookie);

        self::assertSame(405, self::request($client, 'GET', '/logout')['status'], 'a link or image cannot sign anyone out');
        self::assertNotSame(302, self::request($client, 'POST', '/logout', ['csrf' => 'forged'])['status'], 'nor a forged form');
        self::assertNotNull(self::cookieValue($client, self::AUTH_COOKIE), 'still signed in');

        $response = self::request($client, 'POST', '/logout', ['csrf' => self::csrfToken(self::request($client, 'GET', '/profile/subscriber/' . self::$admin['s_uuid'])['body'])]);
        self::assertSame(302, $response['status']);
        self::assertSame('/', parse_url($response['location'], PHP_URL_PATH));
        self::assertTrue(self::clearsCookie($response['cookies'], self::AUTH_COOKIE), 'auth cookie cleared');

        $revoked = self::$db->prepare('SELECT as_revoked_at IS NOT NULL FROM auth_sessions WHERE as_token_hash = ?');
        $revoked->execute([hash('sha256', $cookie)]);
        self::assertTrue((bool) $revoked->fetchColumn(), 'auth session revoked');

        // Replaying the old cookie no longer authenticates.
        $replay = self::client();
        curl_setopt($replay, CURLOPT_COOKIE, self::AUTH_COOKIE . '=' . $cookie);
        self::assertSame(403, self::request($replay, 'GET', '/lists')['status']);
    }

    public function testStatePostsRequireTheSessionCsrfToken(): void
    {
        $client = self::client();
        // The sign-in form: without a valid token nothing is sent.
        $missing = self::request($client, 'POST', '/login', ['signin[email]' => 'nobody@ctnlist.test']);
        self::assertSame(422, $missing['status']);
        self::assertStringContainsString('CSRF token is invalid', $missing['body']);
        $forged = self::submitForm($client, '/login', 'signin', ['signin[email]' => 'nobody@ctnlist.test', 'signin[csrf]' => 'forged']);
        self::assertSame(422, $forged['status']);
        self::assertStringNotContainsString('sign-in link has been sent', $forged['body']);

        // An invalid address is shown at the field, so no mail is sent.
        $invalid = self::submitForm($client, '/login', 'signin', ['signin[email]' => 'not-an-address']);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="signin_email_error1">Enter a valid email address#', $invalid['body']);
        self::assertStringContainsString('value="not-an-address"', $invalid['body']);

        // Manual action forms still need the session token.
        self::assertSame(403, self::request($client, 'POST', '/auth/request', ['subscriber_token' => 'x'])['status']);
        self::assertSame(403, self::request($client, 'POST', '/auth/request', ['subscriber_token' => 'x', 'csrf' => 'forged'])['status']);
    }

    /** @param list<string> $cookies Set-Cookie header values */
    private static function setsCookie(array $cookies, string $name): bool
    {
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, $name . '=') && !str_starts_with($cookie, $name . '=deleted')) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $cookies Set-Cookie header values */
    private static function clearsCookie(array $cookies, string $name): bool
    {
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, $name . '=deleted') || preg_match('/^' . preg_quote($name, '/') . '=;/', $cookie)) {
                return true;
            }
        }
        return false;
    }

    private static function cookieValue(\CurlHandle $client, string $name): ?string
    {
        foreach (curl_getinfo($client, CURLINFO_COOKIELIST) as $line) {
            $fields = explode("\t", $line);
            if (($fields[5] ?? '') === $name) {
                return $fields[6] ?? null;
            }
        }
        return null;
    }
}
