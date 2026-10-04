<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\AuthLoginTokenRepository;
use App\Repository\AuthSessionRepository;
use App\Repository\SubscriberRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
 * GET /auth/verify?token=…: redeems a one-time magic link, starts an
 * auth session (auth cookie) and redirects to the requested action.
 */
final class MagicLinkAuthenticator extends AbstractAuthenticator
{
    public const PATH = '/auth/verify';
    private const RESULT = '_ctnlist_magic_link';

    public function __construct(
        private readonly Connection $db,
        private readonly AuthLoginTokenRepository $loginTokens,
        private readonly AuthSessionRepository $sessions,
        private readonly SubscriberRepository $subscribers,
        private readonly SubscriberUserProvider $users,
        private readonly AclService $acl,
        private readonly LoginReturnPath $returnPath,
        private readonly AuthCookie $cookie,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('GET') && $request->getPathInfo() === self::PATH;
    }

    public function authenticate(Request $request): Passport
    {
        $token = trim((string) $request->query->get('token', ''));
        if (!AuthCookie::isWellFormed($token)) {
            throw new CustomUserMessageAuthenticationException('Malformed sign-in link.');
        }

        $now = $this->clock->now();
        $result = $this->db->transactional(function () use ($token, $now, $request): ?array {
            $at = $now->format('Y-m-d H:i:s');
            $claim = $this->loginTokens->claim(AuthCookie::hash($token), $at);
            $identity = $claim === null ? null : $this->subscribers->findIdentityById($claim['subscriber_id']);
            if ($claim === null || $identity === null) {
                return null;
            }

            $ip = (string) $request->getClientIp();
            $this->subscribers->recordLogin($identity['s_id'], $at, $ip);
            $this->acl->bootstrapInitialAdministrator($identity['s_id'], $identity['s_email']);

            $sessionToken = AuthCookie::newToken();
            $this->sessions->create(
                $identity['s_id'],
                AuthCookie::hash($sessionToken),
                $at,
                $now->modify('+' . $this->cookie->ttl . ' seconds')->format('Y-m-d H:i:s'),
                $ip,
                (string) $request->headers->get('User-Agent', '')
            );

            return [
                'subscriber_id' => $identity['s_id'],
                'session_token' => $sessionToken,
                'redirect' => $this->returnPath->for(
                    $claim['return_action'],
                    $claim['return_message_id'],
                    $claim['return_list_id'],
                    $identity['s_uuid']
                ),
            ];
        });
        if ($result === null) {
            throw new CustomUserMessageAuthenticationException('Invalid, used or expired sign-in link.');
        }

        $request->attributes->set(self::RESULT, $result);
        return new SelfValidatingPassport(new UserBadge(
            (string) $result['subscriber_id'],
            fn(string $id): SubscriberUser => $this->users->loadUserBySubscriberId((int) $id)
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        /** @var array{session_token: string, redirect: string} $result */
        $result = $request->attributes->get(self::RESULT);
        $response = new RedirectResponse($result['redirect']);
        $response->headers->setCookie($this->cookie->create($result['session_token'], $request));
        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new RedirectResponse('/login');
    }
}
