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

    /**
     * The references kept when the body is pruned: the send job or validation
     * job an event concerns (data.id, or a message event's
     * data.message.send_job_id) and a message event's recipient reference.
     */
    private const REFERENCES = "related AS (SELECT CASE WHEN pg_input_is_valid(b.body, 'jsonb') THEN b.body::jsonb END AS doc FROM body b),
        refs AS (SELECT
            CASE WHEN (doc #>> '{data,message,send_job_id}') ~* '^[0-9a-f-]{36}$' THEN (doc #>> '{data,message,send_job_id}')::uuid
                 WHEN (doc #>> '{data,id}') ~* '^[0-9a-f-]{36}$' THEN (doc #>> '{data,id}')::uuid END AS related_id,
            CASE WHEN (doc #>> '{data,message,external_recipient_reference}') ~* '^[0-9a-f-]{36}$'
                 THEN (doc #>> '{data,message,external_recipient_reference}')::uuid END AS recipient_ref
            FROM related)";

    /** @return int|null the new row id, or null when the event was already received */
    public function store(string $eventId, string $type, string $payload): ?int
    {
        $id = $this->db->fetchOne(
            'WITH body AS (SELECT CAST(:payload AS TEXT) AS body), ' . self::REFERENCES . '
             INSERT INTO cattomail_webhook_events (cwe_event_id, cwe_type, cwe_payload, cwe_related_id, cwe_recipient_ref, cwe_received_at)
             SELECT :event, :type, :payload, refs.related_id, refs.recipient_ref, :at FROM refs
             ON CONFLICT (cwe_event_id) DO NOTHING RETURNING cwe_id',
            ['event' => strtolower($eventId), 'type' => mb_substr($type, 0, 80), 'payload' => $payload, 'at' => $this->now()]
        );
        return $id === false ? null : (int) $id;
    }

    /**
     * Remove the raw bodies of events processed before $before (at most
     * $limit rows per call; one statement, so a batch is all or nothing).
     * Unprocessed events are never touched: their body is still needed.
     * The references are filled first for rows stored before they existed.
     *
     * @return int bodies removed
     */
    public function pruneBodies(string $before, int $limit): int
    {
        return (int) $this->db->executeStatement(
            "UPDATE cattomail_webhook_events e SET
                cwe_related_id = COALESCE(e.cwe_related_id, r.related_id), cwe_recipient_ref = COALESCE(e.cwe_recipient_ref, r.recipient_ref),
                cwe_payload = NULL, cwe_payload_pruned_at = :now
             FROM (SELECT x.cwe_id,
                     CASE WHEN (d.doc #>> '{data,message,send_job_id}') ~* '^[0-9a-f-]{36}$' THEN (d.doc #>> '{data,message,send_job_id}')::uuid
                          WHEN (d.doc #>> '{data,id}') ~* '^[0-9a-f-]{36}$' THEN (d.doc #>> '{data,id}')::uuid END AS related_id,
                     CASE WHEN (d.doc #>> '{data,message,external_recipient_reference}') ~* '^[0-9a-f-]{36}$'
                          THEN (d.doc #>> '{data,message,external_recipient_reference}')::uuid END AS recipient_ref
                   FROM cattomail_webhook_events x
                   CROSS JOIN LATERAL (SELECT CASE WHEN pg_input_is_valid(x.cwe_payload, 'jsonb') THEN x.cwe_payload::jsonb END AS doc) d
                   WHERE x.cwe_payload IS NOT NULL AND x.cwe_processed_at IS NOT NULL AND x.cwe_processed_at < :before
                   ORDER BY x.cwe_processed_at LIMIT " . max(1, $limit) . ") r
             WHERE e.cwe_id = r.cwe_id",
            ['now' => $this->now(), 'before' => $before]
        );
    }

    /** Bodies pruneBodies() would remove now (for --dry-run). */
    public function prunableBodies(string $before): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM cattomail_webhook_events WHERE cwe_payload IS NOT NULL AND cwe_processed_at IS NOT NULL AND cwe_processed_at < ?', [$before]
        );
    }

    /**
     * Process an event again (an administrator's recovery action): only one
     * that has not been processed, or failed, and still has its body.
     * Every effect is idempotent, so a repeat cannot apply anything twice.
     *
     * @return int|null the row to process, or null when not possible
     * @phpstan-impure
     */
    public function reopen(string $eventId): ?int
    {
        $id = $this->db->fetchOne(
            "UPDATE cattomail_webhook_events SET cwe_processed_at = NULL, cwe_outcome = NULL, cwe_attempts = 0
             WHERE cwe_event_id = ? AND cwe_payload IS NOT NULL AND (cwe_processed_at IS NULL OR cwe_outcome LIKE 'failed:%') RETURNING cwe_id",
            [strtolower($eventId)]
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

    public const FILTERS = ['' => 'All', 'unprocessed' => 'Not processed yet', 'failed' => 'Failed', 'ignored' => 'Ignored (unknown type or test)'];

    /**
     * One page of received events for the diagnostic list, optionally only
     * those about one catto-mail job ($relatedId). Raw bodies are never read
     * here: events link to ctnlist through their stored references.
     *
     * @return list<array{cwe_event_id: string, cwe_type: string, cwe_received_at: string, cwe_processed_at: ?string, cwe_outcome: ?string,
     *     cwe_error: ?string, cwe_attempts: int, pruned: bool, reprocessable: bool, send_job_id: ?int, validation_job_id: ?int}>
     */
    public function page(string $filter, int $offset, int $limit, ?string $relatedId = null): array
    {
        [$where, $params] = self::where($filter, $relatedId);
        $rows = $this->db->fetchAllAssociative(
            "SELECT e.cwe_event_id, e.cwe_type, e.cwe_received_at, e.cwe_processed_at, e.cwe_outcome, e.cwe_error, e.cwe_attempts,
                    (e.cwe_payload IS NULL) AS pruned, e.cwe_payload IS NOT NULL AND (e.cwe_processed_at IS NULL OR e.cwe_outcome LIKE 'failed:%') AS reprocessable,
                    j.csj_id, v.cvj_id
             FROM cattomail_webhook_events e
             LEFT JOIN cattomail_send_jobs j ON j.csj_remote_id = e.cwe_related_id
             LEFT JOIN cattomail_validation_jobs v ON v.cvj_remote_id = e.cwe_related_id"
            . $where . ' ORDER BY e.cwe_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        return array_map(static fn(array $r): array => [
            'cwe_event_id' => (string) $r['cwe_event_id'], 'cwe_type' => (string) $r['cwe_type'], 'cwe_received_at' => (string) $r['cwe_received_at'],
            'cwe_processed_at' => $r['cwe_processed_at'] === null ? null : (string) $r['cwe_processed_at'],
            'cwe_outcome' => $r['cwe_outcome'] === null ? null : (string) $r['cwe_outcome'], 'cwe_error' => $r['cwe_error'] === null ? null : (string) $r['cwe_error'],
            'cwe_attempts' => (int) $r['cwe_attempts'], 'pruned' => (bool) $r['pruned'], 'reprocessable' => (bool) $r['reprocessable'],
            'send_job_id' => $r['csj_id'] === null ? null : (int) $r['csj_id'], 'validation_job_id' => $r['cvj_id'] === null ? null : (int) $r['cvj_id'],
        ], $rows);
    }

    public function count(string $filter, ?string $relatedId = null): int
    {
        [$where, $params] = self::where($filter, $relatedId);
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_webhook_events e' . $where, $params);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function where(string $filter, ?string $relatedId): array
    {
        $parts = array_filter([match ($filter) {
            'unprocessed' => 'e.cwe_processed_at IS NULL',
            'failed' => "(e.cwe_outcome LIKE 'failed:%' OR (e.cwe_processed_at IS NULL AND e.cwe_error IS NOT NULL))",
            'ignored' => "(e.cwe_outcome LIKE 'ignored%' OR e.cwe_outcome = 'test event')",
            default => '',
        }]);
        $params = [];
        if ($relatedId !== null && preg_match('/^[0-9a-f-]{36}$/i', $relatedId) === 1) {
            $parts[] = 'e.cwe_related_id = :related';
            $params['related'] = strtolower($relatedId);
        }
        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $params];
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
