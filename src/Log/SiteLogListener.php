<?php

declare(strict_types=1);

namespace App\Log;

use App\Security\SubscriberUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Records every request in the Site Log once the response has been sent, so
 * the reverse DNS lookup does not delay the page. Symfony's own /_ tools
 * (profiler, toolbar) are not logged.
 */
#[AsEventListener]
final class SiteLogListener
{
    public function __construct(
        private readonly SiteLog $siteLog,
        private readonly Security $security,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }
        $user = $this->security->getUser();
        $this->siteLog->record($request, $user instanceof SubscriberUser ? $user : null);
    }
}
