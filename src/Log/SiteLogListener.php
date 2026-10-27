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
 * (profiler, toolbar) and asset files (/assets/, served by PHP only in debug
 * mode) are not logged, nor a signed-in user's page region that refreshes
 * itself on a timer (the controller sets NOT_LOGGED: RendersRegions::quietRefresh()).
 */
#[AsEventListener]
final class SiteLogListener
{
    /** Request attribute: this request is not a visit to record. */
    public const NOT_LOGGED = '_site_log_skip';

    public function __construct(
        private readonly SiteLog $siteLog,
        private readonly Security $security,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        // Health checks (monitor polling) are not visits.
        if (str_starts_with($path, '/_') || str_starts_with($path, '/assets/') || $request->attributes->get('_route') === 'health'
            || $request->attributes->getBoolean(self::NOT_LOGGED)) {
            return;
        }
        $user = $this->security->getUser();
        $this->siteLog->record($request, $event->getResponse()->getStatusCode(), $user instanceof SubscriberUser ? $user : null);
    }
}
