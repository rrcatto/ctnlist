<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\AuthSessionRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Revokes the auth session and clears the auth cookie on logout. Runs after
 * Symfony's default listener has created the redirect response.
 */
#[AsEventListener(priority: -10)]
final class LogoutListener
{
    public function __construct(
        private readonly AuthSessionRepository $sessions,
        private readonly AuthCookie $cookie,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $token = $this->cookie->token($request);
        if ($token !== null) {
            $this->sessions->revokeByTokenHash(AuthCookie::hash($token), $this->clock->now()->format('Y-m-d H:i:s'));
        }
        if ($event->getResponse() !== null) {
            $this->cookie->clear($event->getResponse(), $request);
        }
    }
}
