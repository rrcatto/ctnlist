<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The auth cookie (AUTH_SESSION_COOKIE) carries a random token whose SHA-256
 * hash identifies an `auth_sessions` row. It is separate from the PHP session.
 */
final class AuthCookie
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        #[Autowire(env: 'AUTH_SESSION_COOKIE')] public readonly string $name,
        #[Autowire(env: 'int:AUTH_SESSION_TTL')] public readonly int $ttl,
    ) {
    }

    /** 32 random bytes, base64url without padding (43 characters). */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The cookie's token, or null when absent or malformed. */
    public function token(Request $request): ?string
    {
        $token = trim((string) $request->cookies->get($this->name, ''));
        return self::isWellFormed($token) ? $token : null;
    }

    public function create(string $token, Request $request): Cookie
    {
        return Cookie::create($this->name, $token, time() + $this->ttl, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    public function clear(Response $response, Request $request): void
    {
        $response->headers->clearCookie($this->name, '/', null, $request->isSecure(), true, Cookie::SAMESITE_LAX);
    }
}
