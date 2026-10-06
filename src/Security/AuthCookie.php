<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\RuntimeSettings;
use App\Config\SiteConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The auth cookie (AUTH_SESSION_COOKIE) carries a random token whose SHA-256
 * hash identifies an `auth_sessions` row. It is separate from the PHP session.
 *
 * HttpOnly, SameSite=Lax (sent on top-level navigation from mail links, never
 * on cross-site POSTs), path /, host-only, expiring with the auth session.
 * Secure whenever the site is https (APP_BASE_URL) or the request is, so a
 * production proxy that hides HTTPS cannot downgrade it; plain-http
 * development keeps working.
 */
final class AuthCookie
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /** Session lifetime in seconds (AUTH_SESSION_TTL, may be overridden in Settings). */
    public readonly int $ttl;

    public function __construct(
        #[Autowire(env: 'AUTH_SESSION_COOKIE')] public readonly string $name,
        RuntimeSettings $settings,
        private readonly SiteConfig $site,
    ) {
        $this->ttl = $settings->int('AUTH_SESSION_TTL', 86400);
    }

    private function secure(Request $request): bool
    {
        return $request->isSecure() || str_starts_with(strtolower($this->site->baseUrl), 'https://');
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
        return Cookie::create($this->name, $token, time() + $this->ttl, '/', null, $this->secure($request), true, false, Cookie::SAMESITE_LAX);
    }

    public function clear(Response $response, Request $request): void
    {
        $response->headers->clearCookie($this->name, '/', null, $this->secure($request), true, Cookie::SAMESITE_LAX);
    }
}
