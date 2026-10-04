<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/** Clears an auth cookie that AuthSessionAuthenticator rejected. */
#[AsEventListener]
final class AuthCookieClearingListener
{
    public function __construct(private readonly AuthCookie $cookie)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $event->getRequest()->attributes->getBoolean(AuthSessionAuthenticator::CLEAR_COOKIE)) {
            $this->cookie->clear($event->getResponse(), $event->getRequest());
        }
    }
}
