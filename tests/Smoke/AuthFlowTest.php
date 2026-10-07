<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Passwordless authentication, logout and CSRF behaviour of the running stack.
 */
final class AuthFlowTest extends SmokeTestCase
{
    private const PHP_SESSION = 'ctnlist_php_session';
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

    /**
     * Session fixation: the PHP session (CSRF tokens, flashes) gets a new id at
     * sign-in and the old one is destroyed, so an id planted before sign-in
     * (and any CSRF token read from it) is worthless afterwards.
     */
    public function testSignInRotatesThePhpSession(): void
    {
        $client = self::client();
        $login = self::request($client, 'GET', '/login');
        $before = self::cookieValue($client, self::PHP_SESSION);
        self::assertNotNull($before, 'the sign-in form starts a session (CSRF token)');
        $oldToken = self::csrfToken($login['body']);

        $signedIn = self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken(self::$admin['s_id']));
        self::assertSame(302, $signedIn['status']);
        $after = self::cookieValue($client, self::PHP_SESSION);
        self::assertNotNull($after);
        self::assertNotSame($before, $after, 'a new session id after sign-in');
        self::assertSame(0, (int) self::value('SELECT COUNT(*) FROM sessions WHERE ses_id = ?', [$before]), 'the old session is destroyed');

        // A copy of the pre-sign-in session id carries no usable state.
        $planted = self::client();
        curl_setopt($planted, CURLOPT_COOKIE, self::PHP_SESSION . '=' . $before);
        self::assertNotSame(302, self::request($planted, 'POST', '/logout', ['csrf' => $oldToken])['status'], 'its CSRF token is gone');
    }

    public function testLogoutEndsThePhpSession(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $page = self::request($client, 'GET', '/profile/subscriber/' . self::$admin['s_uuid'])['body'];
        $session = self::cookieValue($client, self::PHP_SESSION);
        self::assertNotNull($session);
        self::assertSame(302, self::request($client, 'POST', '/logout', ['csrf' => self::csrfToken($page)])['status']);
        self::assertSame(0, (int) self::value('SELECT COUNT(*) FROM sessions WHERE ses_id = ?', [$session]), 'the PHP session is destroyed at logout');
    }

    /** Two clicks of one link at the same moment (a double click, a link scanner): exactly one signs in. */
    public function testParallelRedemptionSignsInOnce(): void
    {
        $token = self::issueLoginToken(self::$admin['s_id']);
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 4; $i++) {
            $handle = curl_init(self::$baseUrl . '/auth/verify?token=' . $token);
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi);
            }
        } while ($running > 0 && $status === CURLM_OK);
        $signedIn = 0;
        foreach ($handles as $handle) {
            $response = (string) curl_multi_getcontent($handle);
            self::assertSame(302, curl_getinfo($handle, CURLINFO_HTTP_CODE));
            if (preg_match('/^Set-Cookie: ' . self::AUTH_COOKIE . '=[A-Za-z0-9_-]{43};/mi', $response) === 1) {
                $signedIn++;
                self::assertMatchesRegularExpression('#^Location: /profile/subscriber/#mi', $response);
            } else {
                self::assertMatchesRegularExpression('#^Location: \S*/login\s*$#mi', $response);
            }
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
        self::assertSame(1, $signedIn, 'one sign-in from one link');
    }

    public function testAnExpiredLinkSignsNobodyIn(): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); // a well-formed token, so only its age refuses it
        self::$db->prepare('INSERT INTO auth_login_tokens (alt_s_id, alt_email, alt_token_hash, alt_created_at, alt_expires_at, alt_return_action) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::$admin['s_id'], self::$admin['s_email'], hash('sha256', $token), date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() - 60), 'profile']);
        $response = self::request(self::client(), 'GET', '/auth/verify?token=' . $token);
        self::assertSame(302, $response['status']);
        self::assertStringEndsWith('/login', $response['location']);
        self::assertFalse(self::setsCookie($response['cookies'], self::AUTH_COOKIE));
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
