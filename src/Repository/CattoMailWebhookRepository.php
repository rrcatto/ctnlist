<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Received catto-mail webhook events. The unique event id makes a repeated
 * delivery (at-least-once) a no-op; processing happens after the response
 * (and is retried by the worker) and is recorded per event.
 *
 * @phpstan-type WebhookEvent array{cwe_id: int, cwe_event_id: string, cwe_type: string, cwe_payload: string, cwe_attempts: int}
 */
final class CattoMailWebhookRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int|null the new row id, or null when the event was already received */
    public function store(string $eventId, string $type, string $payload): ?int
    {
        $id = $this->db->fetchOne(
            'INSERT INTO cattomail_webhook_events (cwe_event_id, cwe_type, cwe_payload, cwe_received_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (cwe_event_id) DO NOTHING RETURNING cwe_id',
            [strtolower($eventId), mb_substr($type, 0, 80), $payload, $this->now()]
        );
        return $id === false ? null : (int) $id;
    }

    /** @return WebhookEvent|null an unprocessed event */
    public function pending(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT cwe_id, cwe_event_id, cwe_type, cwe_payload, cwe_attempts FROM cattomail_webhook_events WHERE cwe_id = ? AND cwe_processed_at IS NULL', [$id]);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<WebhookEvent> unprocessed events, oldest first */
    public function unprocessed(int $limit = 200): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAllAssociative(
            'SELECT cwe_id, cwe_event_id, cwe_type, cwe_payload, cwe_attempts FROM cattomail_webhook_events
             WHERE cwe_processed_at IS NULL ORDER BY cwe_id LIMIT ' . max(1, $limit)
        ));
    }

    public function markProcessed(int $id, string $outcome): void
    {
        $this->db->executeStatement(
            'UPDATE cattomail_webhook_events SET cwe_processed_at = ?, cwe_outcome = ?, cwe_attempts = cwe_attempts + 1, cwe_error = NULL WHERE cwe_id = ?',
            [$this->now(), mb_substr($outcome, 0, 200), $id]
        );
    }

    public function noteFailure(int $id, string $error): void
    {
        $this->db->executeStatement('UPDATE cattomail_webhook_events SET cwe_attempts = cwe_attempts + 1, cwe_error = ? WHERE cwe_id = ?', [mb_substr($error, 0, 2000), $id]);
    }

    /** @return list<array{cwe_event_id: string, cwe_type: string, cwe_received_at: string, cwe_processed_at: ?string, cwe_outcome: ?string, cwe_error: ?string}> */
    public function recent(int $limit = 50): array
    {
        return array_map(static fn(array $r): array => [
            'cwe_event_id' => (string) $r['cwe_event_id'], 'cwe_type' => (string) $r['cwe_type'], 'cwe_received_at' => (string) $r['cwe_received_at'],
            'cwe_processed_at' => $r['cwe_processed_at'] === null ? null : (string) $r['cwe_processed_at'],
            'cwe_outcome' => $r['cwe_outcome'] === null ? null : (string) $r['cwe_outcome'], 'cwe_error' => $r['cwe_error'] === null ? null : (string) $r['cwe_error'],
        ], $this->db->fetchAllAssociative(
            'SELECT cwe_event_id, cwe_type, cwe_received_at, cwe_processed_at, cwe_outcome, cwe_error FROM cattomail_webhook_events ORDER BY cwe_id DESC LIMIT ' . max(1, $limit)
        ));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     * @return WebhookEvent
     */
    private static function hydrate(array $row): array
    {
        return ['cwe_id' => (int) $row['cwe_id'], 'cwe_event_id' => (string) $row['cwe_event_id'], 'cwe_type' => (string) $row['cwe_type'],
            'cwe_payload' => (string) $row['cwe_payload'], 'cwe_attempts' => (int) $row['cwe_attempts']];
    }
}
