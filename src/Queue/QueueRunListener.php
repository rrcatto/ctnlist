<?php

declare(strict_types=1);

namespace App\Queue;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Sends the queue run that Start sending recorded (QueueRun), once the page has been sent, so the
 * administrator follows it on the Sending page instead of waiting for it. After the Site Log
 * (lower priority), whose record would otherwise wait for the whole run.
 */
#[AsEventListener(priority: -100)]
final class QueueRunListener
{
    public function __construct(#[Autowire(lazy: true)] private readonly QueueRun $run)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if ($event->getRequest()->attributes->getBoolean(QueueRun::START)) {
            ignore_user_abort(true);
            set_time_limit(86400);
            $this->run->run();
        }
    }
}
