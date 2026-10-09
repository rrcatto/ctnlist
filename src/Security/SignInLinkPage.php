<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\AuthLoginTokenRepository;
use App\Repository\ListRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The page a sign-in link opens (GET /auth/verify): a usable link shows a
 * Sign in button that POSTs it to MagicLinkAuthenticator; any other link says
 * why it cannot be used. Showing it only reads the link, so mail scanners and
 * link previews that open it use nothing up.
 */
final class SignInLinkPage
{
    public function __construct(
        private readonly AuthLoginTokenRepository $tokens,
        private readonly ListRepository $lists,
        private readonly ClockInterface $clock,
        private readonly Environment $twig,
    ) {
    }

    /** $retry: a sign-in attempt from this page was refused for its CSRF token (403), the link is still unused. */
    public function show(string $token, bool $retry = false): Response
    {
        $link = AuthCookie::isWellFormed($token)
            ? $this->tokens->find(AuthCookie::hash($token), $this->clock->now()->format('Y-m-d H:i:s'))
            : null;
        $state = match (true) {
            $link === null => 'invalid',
            $link['used'] => 'used',
            $link['expired'] => 'expired',
            default => 'usable',
        };
        // Asked for to confirm a list: say so, and that nothing is confirmed yet.
        $list = $state === 'usable' && $link['return_action'] === 'confirm' && $link['return_list_id'] > 0
            ? $this->lists->findById($link['return_list_id'])
            : null;

        return new Response(
            $this->twig->render('auth/verify.html.twig', [
                'state' => $state,
                'token' => $state === 'usable' ? $token : '',
                'confirm_list' => $list['l_name'] ?? null,
                'retry' => $retry,
            ]),
            match ($state) {
                'usable' => $retry ? Response::HTTP_FORBIDDEN : Response::HTTP_OK,
                'invalid' => Response::HTTP_NOT_FOUND,
                default => Response::HTTP_GONE,
            }
        );
    }
}
