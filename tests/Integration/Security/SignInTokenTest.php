<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Repository\AuthLoginTokenRepository;
use App\Security\AuthCookie;
use App\Security\LoginReturnPath;
use App\Security\MagicLinkRequester;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Mime\Email;

/**
 * Sign-in tokens: unguessable, stored only as hashes, single-use, bounded by
 * the configured lifetime, and returning only to internal pages. (Parallel
 * redemption over HTTP: Smoke\AuthFlowTest.)
 */
final class SignInTokenTest extends IntegrationTestCase
{
    public function testTokensAre256BitRandomAndStoredOnlyAsHashes(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $requester = $this->service(MagicLinkRequester::class);
        self::assertTrue($requester->request('jane@example.com'));
        self::assertTrue($requester->request('jane@example.com'));

        $tokens = [];
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            self::assertSame(1, preg_match('#/auth/verify\?token=([A-Za-z0-9_-]+)#', (string) $message->getTextBody(), $m));
            $tokens[] = $m[1] ?? '';
        }
        self::assertCount(2, $tokens);
        self::assertNotSame($tokens[0], $tokens[1]);
        foreach ($tokens as $token) {
            self::assertSame(43, strlen($token), '32 random bytes, base64url');
        }
        $stored = $this->db->fetchFirstColumn('SELECT alt_token_hash FROM auth_login_tokens WHERE alt_s_id = ? ORDER BY alt_id', [$id]);
        self::assertSame(array_map(AuthCookie::hash(...), $tokens), $stored, 'only the SHA-256 hash is stored');
        self::assertSame(64, strlen((string) $stored[0]));

        $expires = (int) strtotime((string) $this->db->fetchOne('SELECT alt_expires_at FROM auth_login_tokens WHERE alt_s_id = ? ORDER BY alt_id LIMIT 1', [$id]));
        $created = (int) strtotime((string) $this->db->fetchOne('SELECT alt_created_at FROM auth_login_tokens WHERE alt_s_id = ? ORDER BY alt_id LIMIT 1', [$id]));
        self::assertSame($requester->lifetimeSeconds(), $expires - $created, 'the configured lifetime');
    }

    public function testATokenWorksOnceAndNeverAfterItExpires(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $repository = $this->service(AuthLoginTokenRepository::class);
        $insert = fn(string $hash, string $expires) => $this->db->insert('auth_login_tokens', ['alt_s_id' => $id, 'alt_email' => 'jane@example.com',
            'alt_token_hash' => $hash, 'alt_created_at' => date('Y-m-d H:i:s', time() - 60), 'alt_expires_at' => $expires, 'alt_return_action' => 'profile']);
        $now = date('Y-m-d H:i:s');

        $insert($valid = AuthCookie::hash(AuthCookie::newToken()), date('Y-m-d H:i:s', time() + 600));
        self::assertNotNull($repository->claim($valid, $now));
        self::assertNull($repository->claim($valid, $now), 'claimed once (the conditional UPDATE also settles parallel redemptions)');

        $insert($expired = AuthCookie::hash(AuthCookie::newToken()), date('Y-m-d H:i:s', time() - 1));
        self::assertNull($repository->claim($expired, $now));
        self::assertNull($repository->claim(AuthCookie::hash('never-issued'), $now));
    }

    /** Return actions name internal pages; nothing a client sends becomes a redirect target. */
    public function testReturnPathsAreAlwaysInternal(): void
    {
        $paths = $this->service(LoginReturnPath::class);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com'));
        foreach (['https://evil.example/', '//evil.example/', '/\\evil.example/', '%2F%2Fevil.example', 'javascript:alert(1)', "profile\r\nLocation: https://evil.example/"] as $action) {
            self::assertSame('/profile/subscriber/' . $uuid, $paths->for($action, 0, 0, $uuid), $action);
        }
        self::assertSame('/profile/subscriber/%2F%2Fevil.example', $paths->for('profile', 0, 0, '//evil.example'), 'path parts are encoded');
    }
}
