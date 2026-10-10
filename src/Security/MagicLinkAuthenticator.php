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
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * POST /auth/verify (the Sign in button of SignInLinkPage, which the emailed
 * GET link shows): checks the session CSRF token, then redeems the one-time
 * magic link, starts an auth session (auth cookie) and redirects to the
 * requested action. Opening the link never gets here, so a mail scanner that
 * follows it uses nothing up.
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
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly SignInLinkPage $page,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && $request->getPathInfo() === self::PATH;
    }

    public function authenticate(Request $request): Passport
    {
        // Before the claim, so a forged or stale form leaves the link unused.
        if (!$this->csrf->isTokenValid(new CsrfToken(Csrf::TOKEN_ID, $request->request->getString(Csrf::FIELD)))) {
            throw new InvalidCsrfTokenException();
        }
        $token = trim($request->request->getString('token'));
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
        // The firewall is stateless, so Symfony does not rotate the PHP session (CSRF tokens, flash
        // messages) at sign-in: rotate it here, so a session id planted before sign-in is worthless
        // after it, and drop the CSRF token, so a token known before sign-in is too (as Symfony's
        // own session strategy does).
        if ($request->hasPreviousSession()) {
            $session = $request->getSession();
            $session->start(); // sessions start lazily; an unstarted one cannot be regenerated
            $session->migrate(true);
            $this->csrf->removeToken(Csrf::TOKEN_ID);
        }
        $response = new RedirectResponse($result['redirect']);
        $response->headers->setCookie($this->cookie->create($result['session_token'], $request));
        return $response;
    }

    /** The link's page again: why it cannot be used, or (CSRF refused, link unused) its Sign in button. */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->page->show(trim($request->request->getString('token')), $exception instanceof InvalidCsrfTokenException);
    }
}
