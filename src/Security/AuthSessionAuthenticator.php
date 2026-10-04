<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\AuthSessionRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates every request that carries the auth cookie against
 * `auth_sessions` (the firewall is stateless). An invalid cookie leaves the
 * request anonymous and is cleared by AuthCookieClearingListener.
 */
final class AuthSessionAuthenticator extends AbstractAuthenticator
{
    public const CLEAR_COOKIE = '_ctnlist_clear_auth_cookie';
    private const TOUCH_AFTER_SECONDS = 300;

    public function __construct(
        private readonly AuthSessionRepository $sessions,
        private readonly SubscriberUserProvider $users,
        private readonly AuthCookie $cookie,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->cookies->has($this->cookie->name)
            && $request->getPathInfo() !== MagicLinkAuthenticator::PATH;
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->cookie->token($request);
        if ($token === null) {
            throw new CustomUserMessageAuthenticationException('Malformed auth cookie.');
        }

        $now = $this->clock->now();
        $session = $this->sessions->findActive(AuthCookie::hash($token), $now->format('Y-m-d H:i:s'));
        if ($session === null) {
            throw new CustomUserMessageAuthenticationException('Unknown, revoked or expired auth session.');
        }
        $lastSeen = strtotime($session['as_last_seen_at']) ?: 0;
        if ($lastSeen < $now->getTimestamp() - self::TOUCH_AFTER_SECONDS) {
            $this->sessions->touch($session['as_id'], $now->format('Y-m-d H:i:s'));
        }

        return new SelfValidatingPassport(new UserBadge(
            (string) $session['as_s_id'],
            fn(string $id): SubscriberUser => $this->users->loadUserBySubscriberId((int) $id)
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->attributes->set(self::CLEAR_COOKIE, true);
        return null;
    }
}
