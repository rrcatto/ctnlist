<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\CattoMailSendRepository;
use App\Repository\CattoMailWebhookRepository;
use Psr\Log\LoggerInterface;

/**
 * Applies stored catto-mail webhook events. Every effect is idempotent and
 * order-independent (states never move backwards; bounce and complaint
 * effects are claimed once per delivery), so a repeated, delayed or
 * reordered event cannot corrupt ctnlist state. Unknown event types are kept
 * and marked as ignored. An event whose processing needs catto-mail and
 * cannot reach it stays unprocessed for the worker.
 *
 * - validation.completed / validation.failed: the validation job's state;
 *   completed jobs have their results fetched and mapped by subscriber UUID.
 * - send.completed / send.failed: the send job's state and every message
 *   state (GET /v1/send-jobs/{id}/messages).
 * - message.hard_bounced / message.complained: DeliveryProblems.
 * - webhook.test: acknowledged only.
 *
 * @phpstan-import-type WebhookEvent from CattoMailWebhookRepository
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly CattoMailWebhookRepository $events,
        private readonly CattoMailSendRepository $outbox,
        private readonly AddressValidation $validation,
        private readonly SendJobSync $sendSync,
        private readonly DeliveryProblems $problems,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processStored(int $rowId): void
    {
        $event = $this->events->pending($rowId);
        if ($event !== null) {
            $this->process($event);
        }
    }

    /** @return int events processed */
    public function processPending(int $limit = 200): int
    {
        $done = 0;
        foreach ($this->events->unprocessed($limit) as $event) {
            if ($this->process($event)) {
                $done++;
            }
        }
        return $done;
    }

    /** @param WebhookEvent $event */
    private function process(array $event): bool
    {
        try {
            $payload = json_decode($event['cwe_payload'], true, 64, JSON_THROW_ON_ERROR);
            $data = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $outcome = $this->apply($event['cwe_type'], $data);
        } catch (CattoMailUnavailable $e) {
            $this->events->noteFailure($event['cwe_id'], $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            // A permanent problem with this event (unreadable, refused lookup): record it, never retry forever.
            $this->logger->error('catto-mail webhook {id} could not be processed: {error}', ['id' => $event['cwe_event_id'], 'error' => $e->getMessage()]);
            $this->events->noteFailure($event['cwe_id'], $e->getMessage());
            if ($event['cwe_attempts'] + 1 >= 5) {
                $this->events->markProcessed($event['cwe_id'], 'failed: ' . mb_substr($e->getMessage(), 0, 150));
            }
            return false;
        }
        $this->events->markProcessed($event['cwe_id'], $outcome);
        return true;
    }

    /** @param array<string, mixed> $data */
    private function apply(string $type, array $data): string
    {
        return match ($type) {
            'validation.completed', 'validation.failed' => $this->validation->apply($data),
            'send.completed', 'send.failed' => $this->sendSync->apply($data),
            'message.hard_bounced' => $this->messageEvent($data, 'hard_bounced'),
            'message.complained' => $this->messageEvent($data, 'complained'),
            'webhook.test' => 'test event',
            default => 'ignored unknown event type',
        };
    }

    /** @param array<string, mixed> $data a MessageWebhookData */
    private function messageEvent(array $data, string $status): string
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $recipient = $this->outbox->recipientByUuid((string) ($message['external_recipient_reference'] ?? ''));
        if ($recipient === null) {
            return 'unknown recipient';
        }
        $job = $this->outbox->job($recipient['crp_csj_id']);
        if ($job !== null && $job['csj_remote_id'] !== null && strtolower((string) ($message['send_job_id'] ?? '')) !== $job['csj_remote_id']) {
            return 'recipient does not belong to that send job';
        }
        return $this->problems->apply($recipient, $status, is_string($message['id'] ?? null) ? $message['id'] : null,
            is_string($message['resolved_at'] ?? null) ? $message['resolved_at'] : null);
    }
}
