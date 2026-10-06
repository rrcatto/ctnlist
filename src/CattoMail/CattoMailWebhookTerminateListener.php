<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Controller\CattoMailWebhookController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Processes a webhook event stored by CattoMailWebhookController once its
 * acknowledgement has been sent, so catto-mail never waits for ctnlist's
 * processing. Anything that fails here stays unprocessed for the worker.
 */
#[AsEventListener]
final class CattoMailWebhookTerminateListener
{
    public function __construct(private readonly WebhookProcessor $processor)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $rowId = $event->getRequest()->attributes->get(CattoMailWebhookController::PENDING_EVENT_ATTRIBUTE);
        if (is_int($rowId)) {
            $this->processor->processStored($rowId);
        }
    }
}
