<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A stateful stand-in for catto-mail's public /v1 API (OpenAPI
 * 1.0.0-draft.7) at the HTTP boundary, for Symfony's MockHttpClient. It
 * enforces what ctnlist relies on: Bearer authentication, Idempotency-Key
 * semantics (same key + same body replays with Idempotent-Replayed, same key
 * + different body is 422), batch and job limits, duplicate addresses,
 * subscription/transactional unsubscribe rules, submit sealing and the
 * global-opt-out capability. Faults can be injected, including "processed
 * but the response was lost", to prove that retries never duplicate work.
 */
final class FakeCattoMail
{
    public const BASE = 'https://cattomail.test/v1';
    public const KEY = 'shk_test_key_0123456789';

    /** @var list<array{method: string, path: string, headers: array<string, string>, body: mixed}> */
    public array $requests = [];
    /** @var array<string, array<string, mixed>> */
    public array $validationJobs = [];
    /** @var array<string, list<array<string, mixed>>> job id => submitted addresses */
    public array $validationAddresses = [];
    /** @var array<string, array<string, mixed>> */
    public array $sendJobs = [];
    /** @var array<string, list<array<string, mixed>>> job id => accepted recipients */
    public array $recipients = [];
    /** @var array<string, list<array<string, mixed>>> job id => messages (after deliver()) */
    public array $messages = [];
    /** @var array<string, array<string, mixed>> */
    public array $optOuts = [];
    /** @var array<string, array<string, true>> job id => accepted normalised addresses */
    private array $addresses = [];
    public bool $optOutCapability = true;
    /** @var list<string> registered sender domains */
    public array $senderDomains = ['ctnlist.test', 'smarthost-dev.test'];
    /** @var array<string, array{hash: string, status: int, location: string, id: string, result: array<string, mixed>}> */
    private array $idempotency = [];
    /** @var list<array{match: string, kind: string, after: bool}> */
    private array $faults = [];
    private int $sequence = 0;

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $path = (string) substr((string) parse_url($url, PHP_URL_PATH), strlen('/v1'));
        $headers = [];
        foreach ($options['headers'] ?? [] as $line) {
            [$name, $value] = array_pad(explode(':', (string) $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }
        $body = isset($options['body']) && is_string($options['body']) && $options['body'] !== '' ? json_decode($options['body'], true) : null;
        $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body];

        $fault = $this->takeFault($method . ' ' . $path);
        if ($fault !== null && !$fault['after']) {
            return $this->faultResponse($fault['kind']);
        }
        if (($headers['authorization'] ?? '') !== 'Bearer ' . self::KEY) {
            return self::problem(401, 'unauthorized', 'Missing or invalid API key.');
        }
        $response = $this->route($method, $path, $headers, is_array($body) ? $body : []);
        return $fault !== null ? $this->faultResponse($fault['kind']) : $response;
    }

    /**
     * Make the next request matching "<METHOD> <path regex>" fail.
     *
     * @param string $kind timeout or 503
     * @param bool $afterEffect process it first, then lose the response
     */
    public function failNext(string $match, string $kind = 'timeout', bool $afterEffect = false, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->faults[] = ['match' => $match, 'kind' => $kind, 'after' => $afterEffect];
        }
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, body: mixed}> */
    public function requestsTo(string $method, string $pathPattern): array
    {
        return array_values(array_filter($this->requests, static fn(array $r): bool => $r['method'] === $method && preg_match('#^' . $pathPattern . '$#', $r['path']) === 1));
    }

    /**
     * Process a sealed job: one message per recipient with the given status
     * (by email; default remote_accepted). Returns the messages.
     *
     * @param array<string, string> $statusByEmail
     * @return list<array<string, mixed>>
     */
    public function deliver(string $jobId, array $statusByEmail = [], string $jobStatus = 'completed'): array
    {
        $messages = [];
        foreach ($this->recipients[$jobId] ?? [] as $recipient) {
            $status = $statusByEmail[strtolower($recipient['email_address'])] ?? 'remote_accepted';
            $messages[] = [
                'id' => $this->uuid(), 'send_job_id' => $jobId, 'external_recipient_reference' => $recipient['external_recipient_reference'],
                'recipient_address' => $recipient['email_address'], 'current_status' => $status,
                'created_at' => '2026-10-06T10:00:00Z', 'resolved_at' => in_array($status, ['remote_accepted', 'hard_bounced', 'complained', 'failed', 'suppressed'], true) ? '2026-10-06T10:01:00Z' : null,
            ];
        }
        $this->messages[$jobId] = $messages;
        $this->sendJobs[$jobId]['status'] = $jobStatus;
        $this->sendJobs[$jobId]['completed_at'] = $jobStatus === 'completed' ? '2026-10-06T10:05:00Z' : null;
        $this->sendJobs[$jobId]['summary_counts'] = self::counts(array_column($messages, 'current_status'), self::MESSAGE_STATUSES);
        return $messages;
    }

    /**
     * Finish a validation job; $results maps address => partial ValidationAddress fields.
     *
     * @param array<string, array<string, mixed>> $results
     */
    public function completeValidation(string $jobId, array $results = [], string $status = 'completed'): void
    {
        $classes = [];
        foreach ($this->validationAddresses[$jobId] as &$address) {
            $address = array_merge($address, ['checked_at' => '2026-10-06T10:00:00Z', 'syntax_status' => 'valid', 'overall_classification' => 'deliverable', 'confidence' => 'high'],
                $results[$address['original_address']] ?? []);
            $classes[] = $address['overall_classification'];
        }
        unset($address);
        $this->validationJobs[$jobId]['status'] = $status;
        $this->validationJobs[$jobId]['processed_count'] = count($this->validationAddresses[$jobId]);
        $this->validationJobs[$jobId]['completed_at'] = '2026-10-06T10:05:00Z';
        $this->validationJobs[$jobId]['classification_counts'] = self::counts($classes, self::CLASSIFICATIONS);
    }

    public function lastSendJobId(): string
    {
        return (string) array_key_last($this->sendJobs);
    }

    private const MESSAGE_STATUSES = ['created', 'queued', 'submitted', 'deferred', 'outcome_unknown', 'remote_accepted', 'soft_bounced', 'hard_bounced', 'complained', 'failed', 'suppressed'];
    private const CLASSIFICATIONS = ['deliverable', 'probably_deliverable', 'undeliverable', 'temporarily_unverifiable', 'unknown', 'risky'];

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    private function route(string $method, string $path, array $headers, array $body): MockResponse
    {
        $id = '([0-9a-f-]{36})';
        return match (true) {
            $method === 'POST' && $path === '/validation-jobs' => $this->idempotent('validation', $headers, $body, fn() => $this->createValidation($body)),
            $method === 'GET' && preg_match("#^/validation-jobs/$id$#", $path, $m) === 1 => $this->found($this->validationJobs[$m[1]] ?? null),
            $method === 'GET' && preg_match("#^/validation-jobs/$id/addresses$#", $path, $m) === 1 => $this->page($this->validationAddresses[$m[1]] ?? null),
            $method === 'POST' && $path === '/send-jobs' => $this->idempotent('send-jobs', $headers, $body, fn() => $this->createSendJob($body)),
            $method === 'POST' && preg_match("#^/send-jobs/$id/recipients$#", $path, $m) === 1 => $this->idempotent('batch:' . $m[1], $headers, $body, fn() => $this->addBatch($m[1], $body), replayResult: true),
            $method === 'POST' && preg_match("#^/send-jobs/$id/submit$#", $path, $m) === 1 => $this->submit($m[1]),
            $method === 'GET' && preg_match("#^/send-jobs/$id$#", $path, $m) === 1 => $this->found($this->sendJobs[$m[1]] ?? null),
            $method === 'GET' && preg_match("#^/send-jobs/$id/messages$#", $path, $m) === 1 => $this->page(isset($this->sendJobs[$m[1]]) ? ($this->messages[$m[1]] ?? []) : null),
            $method === 'GET' && preg_match("#^/messages/$id/events$#", $path, $m) === 1 => $this->page($this->events($m[1])),
            $method === 'POST' && $path === '/global-suppressions' => $this->optOutCapability
                ? $this->idempotent('optout', $headers, $body, fn() => $this->createOptOut($body), replayResult: true)
                : self::problem(403, 'global-suppression-forbidden', 'The client does not hold the global-opt-out capability.'),
            $method === 'GET' && preg_match("#^/global-suppressions/$id$#", $path, $m) === 1 => $this->found($this->optOuts[$m[1]] ?? null),
            $method === 'POST' && preg_match("#^/global-suppressions/$id/lift$#", $path, $m) === 1 => $this->lift($m[1]),
            default => self::problem(404, 'not-found', 'No such endpoint.'),
        };
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @param \Closure(): array{0: int, 1: array<string, mixed>} $create
     */
    private function idempotent(string $scope, array $headers, array $body, \Closure $create, bool $replayResult = false): MockResponse
    {
        $key = $headers['idempotency-key'] ?? '';
        if (strlen($key) < 8) {
            return self::problem(400, 'bad-request', 'Idempotency-Key is required.');
        }
        $hash = hash('sha256', (string) json_encode($body));
        $stored = $this->idempotency[$scope . '|' . $key] ?? null;
        if ($stored !== null) {
            if ($stored['hash'] !== $hash) {
                return self::problem(422, 'idempotency-key-reused', 'Idempotency-Key reused with a different body.');
            }
            $result = $replayResult ? $stored['result'] : ($this->sendJobs[$stored['id']] ?? $this->validationJobs[$stored['id']] ?? $this->optOuts[$stored['id']] ?? $stored['result']);
            return new MockResponse((string) json_encode($result), ['http_code' => $stored['status'], 'response_headers' => ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']]);
        }
        [$status, $result] = $create();
        if ($status < 300) {
            $this->idempotency[$scope . '|' . $key] = ['hash' => $hash, 'status' => $status, 'location' => '', 'id' => (string) ($result['id'] ?? ''), 'result' => $result];
        }
        return new MockResponse((string) json_encode($result), ['http_code' => $status, 'response_headers' => ['Content-Type' => $status < 300 ? 'application/json' : 'application/problem+json']]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function createValidation(array $body): array
    {
        $addresses = $body['addresses'] ?? [];
        if (!is_array($addresses) || $addresses === [] || count($addresses) > 10000) {
            return [422, self::problemBody(422, 'validation-error', 'addresses must hold 1 to 10000 items.')];
        }
        $id = $this->uuid();
        $this->validationAddresses[$id] = array_values(array_map(fn(array $a): array => [
            'id' => $this->uuid(), 'external_address_reference' => $a['external_address_reference'] ?? null, 'original_address' => $a['address'],
            'normalized_address' => null, 'syntax_status' => null, 'domain_status' => null, 'smtp_status' => null, 'is_role' => null, 'is_disposable' => null,
            'is_catch_all_or_accept_all' => null, 'is_domain_typo_suspected' => null, 'suggested_address' => null, 'suggestion_reason_code' => null,
            'suggestion_confidence' => null, 'overall_classification' => null, 'confidence' => null, 'diagnostic_code' => null, 'diagnostic_text' => null, 'checked_at' => null,
        ], $addresses));
        $this->validationJobs[$id] = ['id' => $id, 'external_reference' => $body['external_reference'] ?? null, 'status' => 'queued', 'submitted_at' => '2026-10-06T09:00:00Z',
            'started_at' => null, 'completed_at' => null, 'total_addresses' => count($addresses), 'processed_count' => 0, 'classification_counts' => self::counts([], self::CLASSIFICATIONS)];
        return [202, $this->validationJobs[$id]];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function createSendJob(array $body): array
    {
        $class = $body['message_class'] ?? '';
        $domain = strtolower((string) substr(strrchr((string) ($body['sender_identity']['email'] ?? ''), '@') ?: '', 1));
        if (!in_array($class, ['transactional', 'subscription'], true) || !isset($body['external_reference'])) {
            return [422, self::problemBody(422, 'validation-error', 'Invalid send job.')];
        }
        if (($class === 'subscription') !== isset($body['list_id'])) {
            return [422, self::problemBody(422, 'validation-error', 'list_id is mandatory for subscription and not accepted for transactional.')];
        }
        if (!in_array($domain, $this->senderDomains, true)) {
            return [422, self::problemBody(422, 'sending-domain-unknown', 'The sender identity domain is not a registered sending domain of the client.')];
        }
        $id = $this->uuid();
        $this->sendJobs[$id] = ['id' => $id, 'external_reference' => $body['external_reference'], 'message_class' => $class, 'list_id' => $body['list_id'] ?? null,
            'sender_identity' => $body['sender_identity'], 'reply_to' => null, 'tracking' => $body['tracking'] ?? ['opens' => false, 'clicks' => false], 'status' => 'collecting',
            'created_at' => '2026-10-06T09:00:00Z', 'queued_at' => null, 'started_at' => null, 'dispatch_completed_at' => null, 'completed_at' => null,
            'total_recipients' => 0, 'summary_counts' => self::counts([], self::MESSAGE_STATUSES)];
        $this->recipients[$id] = [];
        return [201, $this->sendJobs[$id]];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function addBatch(string $jobId, array $body): array
    {
        $job = $this->sendJobs[$jobId] ?? null;
        if ($job === null) {
            return [404, self::problemBody(404, 'not-found', 'Unknown send job.')];
        }
        if ($job['status'] !== 'collecting') {
            return [409, self::problemBody(409, 'job-not-collecting', 'The job is no longer collecting.')];
        }
        $batch = $body['recipients'] ?? [];
        if (!is_array($batch) || $batch === [] || count($batch) > 500) {
            return [422, self::problemBody(422, 'validation-error', 'A batch holds 1 to 500 recipients.')];
        }
        if (count($this->recipients[$jobId]) + count($batch) > 10000) {
            return [422, self::problemBody(422, 'recipient-limit', 'The job would exceed 10000 recipients.')];
        }
        $seen = $this->addresses[$jobId] ?? [];
        foreach ($batch as $n => $recipient) {
            $address = self::normalise((string) ($recipient['email_address'] ?? ''));
            if (isset($seen[$address])) {
                return [422, self::problemBody(422, 'duplicate-recipient', 'Duplicate address.', [['pointer' => "/recipients/$n/email_address", 'message' => 'duplicate']])];
            }
            $seen[$address] = true;
            $unsubscribe = $recipient['unsubscribe_url'] ?? null;
            if ($job['message_class'] === 'subscription' && (!is_string($unsubscribe) || !str_starts_with($unsubscribe, 'https://'))) {
                return [422, self::problemBody(422, 'unsubscribe-url-required', 'Subscription recipients need an https unsubscribe_url.')];
            }
            if ($job['message_class'] === 'transactional' && $unsubscribe !== null) {
                return [422, self::problemBody(422, 'unsubscribe-url-not-accepted', 'Transactional recipients do not accept unsubscribe_url.')];
            }
            if (!isset($recipient['html_body']) && !isset($recipient['text_body']) || ($recipient['subject'] ?? '') === '' || !isset($recipient['external_recipient_reference'])) {
                return [422, self::problemBody(422, 'validation-error', 'Incomplete recipient.')];
            }
        }
        array_push($this->recipients[$jobId], ...$batch);
        $this->addresses[$jobId] = $seen;
        $this->sendJobs[$jobId]['total_recipients'] = count($this->recipients[$jobId]);
        return [201, ['batch_id' => $this->uuid(), 'accepted_count' => count($batch), 'total_recipients' => count($this->recipients[$jobId])]];
    }

    private function submit(string $jobId): MockResponse
    {
        $job = $this->sendJobs[$jobId] ?? null;
        if ($job === null) {
            return self::problem(404, 'not-found', 'Unknown send job.');
        }
        if ($job['status'] === 'collecting') {
            if ($this->recipients[$jobId] === []) {
                return self::problem(422, 'empty-job', 'The job has no recipients.');
            }
            $this->sendJobs[$jobId]['status'] = 'queued';
            $this->sendJobs[$jobId]['queued_at'] = '2026-10-06T09:30:00Z';
        }
        return new MockResponse((string) json_encode($this->sendJobs[$jobId]), ['http_code' => 202, 'response_headers' => ['Content-Type' => 'application/json']]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function createOptOut(array $body): array
    {
        $address = self::normalise((string) ($body['email_address'] ?? ''));
        foreach ($this->optOuts as $optOut) {
            if ($optOut['email_address'] === $address && $optOut['status'] === 'active') {
                return [200, $optOut];
            }
        }
        $id = $this->uuid();
        $this->optOuts[$id] = ['id' => $id, 'email_address' => $address, 'reason' => 'recipient_global_opt_out', 'status' => 'active',
            'external_reference' => $body['external_reference'] ?? null, 'created_at' => '2026-10-06T09:00:00Z', 'lifted_at' => null];
        return [201, $this->optOuts[$id]];
    }

    private function lift(string $id): MockResponse
    {
        if (!$this->optOutCapability) {
            return self::problem(403, 'global-suppression-forbidden', 'No capability.');
        }
        if (!isset($this->optOuts[$id])) {
            return self::problem(404, 'not-found', 'Unknown opt-out.');
        }
        $this->optOuts[$id]['status'] = 'lifted';
        $this->optOuts[$id]['lifted_at'] ??= '2026-10-06T11:00:00Z';
        return new MockResponse((string) json_encode($this->optOuts[$id]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
    }

    /** @return list<array<string, mixed>>|null */
    private function events(string $messageId): ?array
    {
        foreach ($this->messages as $messages) {
            foreach ($messages as $message) {
                if ($message['id'] === $messageId) {
                    return [
                        ['id' => $this->uuid(), 'message_id' => $messageId, 'event_type' => 'submitted_to_postfix', 'smtp_code' => null, 'enhanced_status_code' => null, 'remote_host' => null, 'diagnostic' => null, 'occurred_at' => '2026-10-06T10:00:00Z'],
                        ['id' => $this->uuid(), 'message_id' => $messageId, 'event_type' => 'remote_accepted', 'smtp_code' => 250, 'enhanced_status_code' => '2.0.0', 'remote_host' => 'mx.example', 'diagnostic' => 'queued', 'occurred_at' => '2026-10-06T10:00:05Z'],
                        ['id' => $this->uuid(), 'message_id' => $messageId, 'event_type' => 'open_recorded', 'smtp_code' => null, 'enhanced_status_code' => null, 'remote_host' => null, 'diagnostic' => null, 'occurred_at' => '2026-10-06T10:30:00Z'],
                    ];
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed>|null $resource */
    private function found(?array $resource): MockResponse
    {
        return $resource === null ? self::problem(404, 'not-found', 'Not found.')
            : new MockResponse((string) json_encode($resource), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
    }

    /**
     * Keyset-style pages of 2 items, so pagination is always exercised.
     *
     * @param list<array<string, mixed>>|null $items
     */
    private function page(?array $items): MockResponse
    {
        if ($items === null) {
            return self::problem(404, 'not-found', 'Not found.');
        }
        $offset = isset($this->lastQuery['cursor']) ? (int) base64_decode((string) $this->lastQuery['cursor']) : 0;
        $size = 2;
        $slice = array_slice($items, $offset, $size);
        $next = $offset + $size < count($items) ? base64_encode((string) ($offset + $size)) : null;
        return new MockResponse((string) json_encode(['data' => $slice, 'pagination' => ['limit' => $size, 'next_cursor' => $next]]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
    }

    /** @var array<int|string, mixed> query of the current request */
    private array $lastQuery = [];

    /** Called by the MockHttpClient wrapper before __invoke() with the request URL. */
    public function handler(): \Closure
    {
        return function (string $method, string $url, array $options): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->lastQuery = $query;
            return $this($method, $url, $options);
        };
    }

    /** @return array{match: string, kind: string, after: bool}|null */
    private function takeFault(string $request): ?array
    {
        foreach ($this->faults as $i => $fault) {
            [$method, $pattern] = explode(' ', $fault['match'], 2);
            if (str_starts_with($request, $method . ' ') && preg_match('#^' . $pattern . '$#', substr($request, strlen($method) + 1)) === 1) {
                array_splice($this->faults, $i, 1);
                return $fault;
            }
        }
        return null;
    }

    private function faultResponse(string $kind): MockResponse
    {
        return $kind === 'timeout'
            ? new MockResponse('', ['error' => 'Idle timeout reached for "' . self::BASE . '".'])
            : self::problem((int) $kind, 'unavailable', 'Service unavailable.');
    }

    private function uuid(): string
    {
        $n = ++$this->sequence;
        return sprintf('0199a000-0000-7000-8000-%012x', $n);
    }

    private static function normalise(string $address): string
    {
        $address = trim($address);
        $at = strrpos($address, '@');
        return $at === false ? $address : substr($address, 0, $at) . '@' . strtolower(substr($address, $at + 1));
    }

    /**
     * @param list<string> $values
     * @param list<string> $keys
     * @return array<string, int>
     */
    private static function counts(array $values, array $keys): array
    {
        $counts = array_fill_keys($keys, 0);
        foreach ($values as $value) {
            if (isset($counts[$value])) {
                $counts[$value]++;
            }
        }
        return $counts;
    }

    private static function problem(int $status, string $slug, string $detail): MockResponse
    {
        return new MockResponse((string) json_encode(self::problemBody($status, $slug, $detail)), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/problem+json']]);
    }

    /**
     * @param list<array{pointer: string, message: string}> $errors
     * @return array<string, mixed>
     */
    private static function problemBody(int $status, string $slug, string $detail, array $errors = []): array
    {
        return ['type' => 'https://cattomail.test/problems/' . $slug, 'title' => $slug, 'status' => $status, 'detail' => $detail] + ($errors !== [] ? ['errors' => $errors] : []);
    }
}
