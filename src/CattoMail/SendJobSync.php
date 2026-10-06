<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\CattoMailSendRepository;

/**
 * Brings ctnlist's view of a send job up to date from catto-mail: the job
 * state (from a send.* webhook or GET /v1/send-jobs/{id}) and, once messages
 * exist, each message's state through GET /v1/send-jobs/{id}/messages,
 * mapped back by external_recipient_reference. Safe to repeat and in any
 * order: job and message states never move backwards.
 *
 * @phpstan-import-type SendJob from CattoMailSendRepository
 */
final class SendJobSync
{
    public function __construct(
        private readonly CattoMailSendRepository $outbox,
        private readonly CattoMailClient $client,
        private readonly DeliveryProblems $problems,
    ) {
    }

    /**
     * @param array<string, mixed> $remote a SendJob representation
     * @throws CattoMailException while fetching messages
     */
    public function apply(array $remote): string
    {
        $job = $this->outbox->jobByRemoteId((string) ($remote['id'] ?? ''));
        if ($job === null) {
            return 'unknown send job';
        }
        $status = (string) ($remote['status'] ?? '');
        $summary = is_array($remote['summary_counts'] ?? null) ? $remote['summary_counts'] : null;
        $this->outbox->updateJobState($job['csj_id'], $status, $summary, DeliveryProblems::timestamp(is_string($remote['completed_at'] ?? null) ? $remote['completed_at'] : null));
        $synced = 0;
        if (in_array($status, ['processing', 'dispatched', 'completed', 'failed', 'cancelled'], true)) {
            $synced = $this->syncMessages($job);
        }
        return 'send job ' . $status . ($synced > 0 ? ', ' . $synced . ' message states' : '');
    }

    /**
     * @param SendJob $job
     * @throws CattoMailException
     */
    public function reconcile(array $job): string
    {
        if ($job['csj_remote_id'] === null) {
            return 'not created yet';
        }
        return $this->apply($this->client->getSendJob($job['csj_remote_id']));
    }

    /**
     * @param SendJob $job
     * @return int messages mapped
     * @throws CattoMailException
     */
    public function syncMessages(array $job): int
    {
        if ($job['csj_remote_id'] === null) {
            return 0;
        }
        $mapped = 0;
        $cursor = null;
        do {
            $page = $this->client->listSendJobMessages($job['csj_remote_id'], $cursor);
            foreach ($page['data'] as $message) {
                $recipient = $this->outbox->recipientByUuid((string) ($message['external_recipient_reference'] ?? ''));
                if ($recipient === null || $recipient['crp_csj_id'] !== $job['csj_id']) {
                    continue;
                }
                $this->problems->apply($recipient, (string) ($message['current_status'] ?? ''), (string) ($message['id'] ?? '') ?: null,
                    is_string($message['resolved_at'] ?? null) ? $message['resolved_at'] : null);
                $mapped++;
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        return $mapped;
    }
}
