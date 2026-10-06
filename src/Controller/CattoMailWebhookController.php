<?php

declare(strict_types=1);

namespace App\Controller;

use App\CattoMail\WebhookSignature;
use App\Repository\CattoMailWebhookRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives catto-mail's signed webhooks (no session, no CSRF token: the
 * signature is the authentication). The signature is verified over the raw
 * body before the JSON is parsed; a valid event is stored under its event id
 * (a repeated delivery is acknowledged without a second effect) and
 * acknowledged at once. It is processed after the response is sent
 * (CattoMailWebhookTerminateListener), and again by `ctnlist:cattomail:work`
 * if that did not finish.
 */
final class CattoMailWebhookController extends AbstractController
{
    public const PENDING_EVENT_ATTRIBUTE = '_cattomail_event';
    private const MAX_BODY_BYTES = 1048576;

    #[Route('/cattomail/webhook', name: 'cattomail_webhook', methods: ['POST'])]
    public function receive(Request $request, WebhookSignature $signatures, CattoMailWebhookRepository $events, LoggerInterface $logger): Response
    {
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return self::answer('payload-too-large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $verdict = $signatures->verify($body, (string) $request->headers->get(WebhookSignature::HEADER, ''));
        if ($verdict === WebhookSignature::NOT_CONFIGURED) {
            // Retryable for catto-mail: the event is not lost while the secret is being set up.
            $logger->error('catto-mail webhook received but CATTOMAIL_WEBHOOK_SECRET is not set.');
            return self::answer('not-configured', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ($verdict !== WebhookSignature::VALID) {
            $logger->warning('catto-mail webhook rejected: {verdict} signature.', ['verdict' => $verdict]);
            return self::answer('invalid-signature', Response::HTTP_UNAUTHORIZED);
        }

        $event = json_decode($body, true);
        $id = is_array($event) ? (string) ($event['id'] ?? '') : '';
        $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
        $headerId = strtolower(trim((string) $request->headers->get(WebhookSignature::EVENT_ID_HEADER, '')));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1 || $type === ''
            || ($headerId !== '' && $headerId !== strtolower($id))) {
            return self::answer('invalid-event', Response::HTTP_BAD_REQUEST);
        }

        $rowId = $events->store($id, $type, $body);
        if ($rowId === null) {
            return self::answer('duplicate', Response::HTTP_OK);
        }
        $request->attributes->set(self::PENDING_EVENT_ATTRIBUTE, $rowId);
        return self::answer('accepted', Response::HTTP_OK);
    }

    private static function answer(string $status, int $code): JsonResponse
    {
        return new JsonResponse(['status' => $status], $code);
    }
}
