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

    /**
     * Opening a sign-in link (as a mail scanner or link preview does, any
     * number of times, GET or HEAD) shows a page and changes nothing: no auth
     * cookie or session, no recorded login, the link still unused. The
     * recipient's Sign in (POST) then works.
     */
    public function testOpeningALinkChangesNothingUntilSignIn(): void
    {
        $email = 'smoke-scanned-' . bin2hex(random_bytes(4)) . '@ctnlist.test';
        $subscriber = self::rows('INSERT INTO subscribers (s_email) VALUES (?) RETURNING s_id, s_uuid', [$email])[0];
        $token = self::issueLoginToken((int) $subscriber['s_id']);
        $before = self::authState($token, (int) $subscriber['s_id']);
        self::assertSame(['used' => false, 'sessions' => 0, 'login' => ''], $before);

        foreach (range(1, 3) as $n) {
            $scan = self::request(self::client(), 'GET', '/auth/verify?token=' . $token);
            self::assertSame(200, $scan['status'], "GET {$n}");
            self::assertStringContainsString('<form name="sign_in"', $scan['body'], "GET {$n}: the Sign in button");
            self::assertStringNotContainsString($email, $scan['body'], 'no subscriber details on the page');
            self::assertFalse(self::setsCookie($scan['cookies'], self::AUTH_COOKIE), "GET {$n}: no auth cookie");
        }
        $head = curl_init(self::$baseUrl . '/auth/verify?token=' . $token);
        curl_setopt_array($head, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
        $headers = (string) curl_exec($head);
        self::assertSame(200, curl_getinfo($head, CURLINFO_HTTP_CODE), 'HEAD');
        self::assertDoesNotMatchRegularExpression('/^Set-Cookie: ' . self::AUTH_COOKIE . '=/mi', $headers, 'HEAD: no auth cookie');
        self::assertSame($before, self::authState($token, (int) $subscriber['s_id']), 'GET and HEAD leave the link unused, start no auth session and record no login');

        // The recipient, after the scanner: open the link and choose Sign in.
        $client = self::client();
        $response = self::signInWithLink($client, $token);
        self::assertSame(302, $response['status']);
        self::assertSame('/profile/subscriber/' . $subscriber['s_uuid'], $response['location']);
        self::assertTrue(self::setsCookie($response['cookies'], self::AUTH_COOKIE), 'auth cookie set');
        $after = self::authState($token, (int) $subscriber['s_id']);
        self::assertTrue($after['used'], 'the sign-in uses the link up');
        self::assertSame(1, $after['sessions'], 'one auth session');
        self::assertNotSame('', $after['login'], 'the login is recorded');
        self::assertSame(200, self::request($client, 'GET', '/my/messages')['status'], 'signed in');
    }

    /** The Sign in POST needs the CSRF token of the page's own session; a refused POST leaves the link usable. */
    public function testSignInNeedsThePagesCsrfToken(): void
    {
        $token = self::issueLoginToken(self::$admin['s_id']);
        $client = self::client();
        $page = self::request($client, 'GET', '/auth/verify?token=' . $token);
        $fields = self::formFields($page['body'], 'sign_in');
        self::assertSame($token, $fields['token']);

        $attempts = [
            'no token' => [$client, ['token' => $token]],
            'forged token' => [$client, ['csrf' => 'forged', 'token' => $token]],
            'another session (a cross-site form)' => [self::client(), $fields],
        ];
        $ownRefusal = '';
        foreach ($attempts as $case => [$from, $post]) {
            $refused = self::request($from, 'POST', '/auth/verify', $post);
            if ($from === $client) {
                $ownRefusal = $refused['body'];
            }
            self::assertSame(403, $refused['status'], $case);
            self::assertFalse(self::setsCookie($refused['cookies'], self::AUTH_COOKIE), "{$case}: not signed in");
            self::assertStringContainsString('This page had expired', $refused['body'], "{$case}: the page again, to try again");
        }
        self::assertFalse(self::authState($token, self::$admin['s_id'])['used'], 'refused attempts leave the link unused');

        // "Choose Sign in again": the refusal page's own form signs in.
        $retry = self::request($client, 'POST', '/auth/verify', self::formFields($ownRefusal, 'sign_in'));
        self::assertSame(302, $retry['status'], 'signed in on the second try');
    }

    /** A link signs in once: afterwards its page and a second Sign in say it has been used. */
    public function testALinkSignsInOnce(): void
    {
        $token = self::issueLoginToken(self::$admin['s_id']);
        $first = self::client();
        $page = self::request($first, 'GET', '/auth/verify?token=' . $token);
        $second = self::client();
        $secondPage = self::request($second, 'GET', '/auth/verify?token=' . $token);
        self::assertSame(302, self::request($first, 'POST', '/auth/verify', self::formFields($page['body'], 'sign_in'))['status']);

        $reuse = self::request($second, 'POST', '/auth/verify', self::formFields($secondPage['body'], 'sign_in'));
        self::assertSame(410, $reuse['status'], 'the second tab cannot use it again');
        self::assertFalse(self::setsCookie($reuse['cookies'], self::AUTH_COOKIE));
        self::assertStringContainsString('It has already been used', $reuse['body']);
        self::assertStringContainsString('href="/login"', $reuse['body'], 'offers a new link');

        $reopened = self::request(self::client(), 'GET', '/auth/verify?token=' . $token);
        self::assertSame(410, $reopened['status']);
        self::assertStringNotContainsString('name="sign_in"', $reopened['body'], 'no Sign in button for a used link');
    }

    /**
     * Sign in pressed in several browsers at the same moment (or a double
     * submit): the conditional UPDATE lets exactly one claim the link.
     */
    public function testParallelSignInsSignInOnce(): void
    {
        $token = self::issueLoginToken(self::$admin['s_id']);
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 4; $i++) {
            // Each its own browser: its own session and CSRF token from the page.
            $browser = self::client();
            $fields = self::formFields(self::request($browser, 'GET', '/auth/verify?token=' . $token)['body'], 'sign_in');
            $handle = curl_init(self::$baseUrl . '/auth/verify');
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_COOKIE => self::PHP_SESSION . '=' . self::cookieValue($browser, self::PHP_SESSION),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
            ]);
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
            if (preg_match('/^Set-Cookie: ' . self::AUTH_COOKIE . '=[A-Za-z0-9_-]{43};/mi', $response) === 1) {
                $signedIn++;
                self::assertSame(302, curl_getinfo($handle, CURLINFO_HTTP_CODE));
                self::assertMatchesRegularExpression('#^Location: /profile/subscriber/#mi', $response);
            } else {
                self::assertSame(410, curl_getinfo($handle, CURLINFO_HTTP_CODE), 'the others are told the link was used');
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
        $client = self::client();
        $page = self::request($client, 'GET', '/auth/verify?token=' . $token);
        self::assertSame(410, $page['status']);
        self::assertStringContainsString('It has expired', $page['body']);
        self::assertStringContainsString('href="/login"', $page['body'], 'offers a new link');
        self::assertStringNotContainsString('name="sign_in"', $page['body']);

        $response = self::request($client, 'POST', '/auth/verify', ['csrf' => self::csrfToken(self::request($client, 'GET', '/login')['body']), 'token' => $token]);
        self::assertSame(410, $response['status'], 'a valid CSRF token does not help');
        self::assertFalse(self::setsCookie($response['cookies'], self::AUTH_COOKIE));
        self::assertFalse(self::authState($token, self::$admin['s_id'])['used']);
    }

    public function testMalformedOrUnknownLinksSignNobodyIn(): void
    {
        $client = self::client();
        $csrf = self::csrfToken(self::request($client, 'GET', '/login')['body']);
        foreach (['', 'short', str_repeat('A', 43), '../../etc'] as $token) {
            $page = self::request($client, 'GET', '/auth/verify?token=' . rawurlencode($token));
            self::assertSame(404, $page['status'], "GET token '{$token}'");
            self::assertStringContainsString('It is not valid', $page['body'], "token '{$token}'");
            $response = self::request($client, 'POST', '/auth/verify', ['csrf' => $csrf, 'token' => $token]);
            self::assertSame(404, $response['status'], "POST token '{$token}'");
            self::assertFalse(self::setsCookie($response['cookies'], self::AUTH_COOKIE), "token '{$token}'");
        }
    }

    public function testSignInReturnsToTheRequestedAction(): void
    {
        $response = self::signInWithLink(self::client(), self::issueLoginToken(self::$admin['s_id'], 'messages'));
        self::assertSame(302, $response['status']);
        self::assertSame('/my/messages', $response['location']);
    }

    /**
     * A link requested to confirm a list says so, and neither opening it nor
     * signing in confirms anything: sign-in leads to the list's confirmation
     * page, and only its own Confirm (POST) gives consent.
     */
    public function testALinkToConfirmAListConfirmsNothingByItself(): void
    {
        $email = 'smoke-confirm-' . bin2hex(random_bytes(4)) . '@ctnlist.test';
        $subscriber = self::rows('INSERT INTO subscribers (s_email) VALUES (?) RETURNING s_id, s_uuid', [$email])[0];
        $shortcode = 'C' . strtoupper(bin2hex(random_bytes(2)));
        $listId = (int) self::value('INSERT INTO lists (l_shortcode, l_name) VALUES (?, ?) RETURNING l_id', [$shortcode, 'Confirm ' . $shortcode]);
        $consent = static fn(): string => (string) self::value(
            "SELECT COUNT(*) || ':' || COALESCE(BOOL_OR(ls_confirmed)::text, '-') || ':'
                 || (SELECT COUNT(*) FROM list_subscription_events JOIN list_subscribers m ON m.ls_id = lse_ls_id WHERE m.ls_s_id = ?)
             FROM list_subscribers WHERE ls_s_id = ?",
            [$subscriber['s_id'], $subscriber['s_id']]
        );
        $nothing = $consent();
        $token = self::issueLoginToken((int) $subscriber['s_id'], 'confirm', $listId);

        $client = self::client();
        $page = self::request($client, 'GET', '/auth/verify?token=' . $token);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Sign in to confirm your subscription to <strong>Confirm ' . $shortcode . '</strong>', $page['body']);
        self::assertStringContainsString('You are subscribed only once you confirm', $page['body']);
        self::assertStringNotContainsString($email, $page['body']);
        self::assertSame($nothing, $consent(), 'opening the link confirms nothing');

        $signedIn = self::request($client, 'POST', '/auth/verify', self::formFields($page['body'], 'sign_in'));
        self::assertSame(302, $signedIn['status']);
        self::assertSame("/confirm/{$subscriber['s_uuid']}/{$shortcode}", $signedIn['location']);
        self::assertSame($nothing, $consent(), 'signing in confirms nothing');

        $confirm = self::request($client, 'GET', $signedIn['location']);
        self::assertSame(200, $confirm['status']);
        self::assertStringContainsString('Confirm subscription to Confirm ' . $shortcode, $confirm['body']);
        self::assertSame($nothing, $consent(), 'nor does the confirmation page');
        $done = self::request($client, 'POST', '/confirm', [
            'csrf' => self::csrfToken($confirm['body']), 'subscriber_token' => (string) $subscriber['s_uuid'], 'list_shortcode' => $shortcode, 'muid' => '',
        ]);
        self::assertStringContainsString('is confirmed', $done['body']);
        self::assertTrue((bool) self::value('SELECT ls_confirmed FROM list_subscribers WHERE ls_s_id = ? AND ls_l_id = ?', [$subscriber['s_id'], $listId]), 'consent comes from the explicit Confirm');
    }

    /**
     * Session fixation: the PHP session (CSRF tokens, flashes) gets a new id at
     * sign-in and the old one is destroyed, so an id planted before sign-in
     * (and any CSRF token read from it) is worthless afterwards.
     */
    public function testSignInRotatesThePhpSession(): void
    {
        $client = self::client();
        $page = self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken(self::$admin['s_id']));
        $before = self::cookieValue($client, self::PHP_SESSION);
        self::assertNotNull($before, 'the sign-in page starts a session (CSRF token)');
        $oldToken = self::csrfToken($page['body']);

        $signedIn = self::request($client, 'POST', '/auth/verify', self::formFields($page['body'], 'sign_in'));
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

    /**
     * What a sign-in changes: whether the link is used, the subscriber's auth sessions and last login.
     *
     * @return array{used: bool, sessions: int, login: string}
     */
    private static function authState(string $token, int $subscriberId): array
    {
        return [
            'used' => (bool) self::value('SELECT alt_used_at IS NOT NULL FROM auth_login_tokens WHERE alt_token_hash = ?', [hash('sha256', $token)]),
            'sessions' => (int) self::value('SELECT COUNT(*) FROM auth_sessions WHERE as_s_id = ?', [$subscriberId]),
            'login' => (string) self::value('SELECT s_last_login_at FROM subscribers WHERE s_id = ?', [$subscriberId]),
        ];
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
