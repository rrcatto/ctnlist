<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A stateful stand-in for catto-mail's public /v1 API (OpenAPI
 * 1.0.0-draft.11) at the HTTP boundary, for Symfony's MockHttpClient. It
 * enforces what ctnlist relies on: Bearer authentication, Idempotency-Key
 * semantics (same key + same body replays with Idempotent-Replayed, same key
 * + different body is 422), batch, job and request-size (413) limits, duplicate addresses,
 * subscription/transactional unsubscribe rules, submit sealing and the
 * global-opt-out capability. Faults can be injected, including "processed
 * but the response was lost", to prove that retries never duplicate work.
 *
 * The connect flow, with draft.11's optional sending_domain (approving adds it
 * to the client as a domain still to be set up): approve() and decline() stand
 * in for catto-mail's connect page and the person at it, with that page's
 * parameter rules; POST /v1/connect/exchange (the one operation without an
 * API key) checks the single-use code, its 10 minutes, the redirect URI and
 * the PKCE verifier, and answers every code it does not accept with the same
 * invalid-connect-code; DELETE /v1/connection ends the connection of the key
 * that asks; GET /v1/sending-domains lists the client's domains with can_send
 * and setup_url (draft.11: the domain's page in the dashboard). A key issued
 * by a connection works on every operation until its connection ends.
 */
final class FakeCattoMail
{
    public const BASE = 'https://cattomail.test/v1';
    /** The installation's address: what an administrator types, and where its connect page is. */
    public const ORIGIN = 'https://cattomail.test';
    public const KEY = 'shk_test_key_0123456789';
    public const CONNECT_EVENTS = ['message.complained', 'message.hard_bounced', 'send.completed', 'send.failed', 'validation.completed', 'validation.failed'];

    /** @var list<array{method: string, path: string, url: string, headers: array<string, string>, body: mixed}> */
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
    /** @var array<string, array<string, mixed>> the account's blocks (catto-mail 2.13, /v1/blocks), by id */
    public array $blocks = [];
    /** Whether the client is active or throttled: lifting a block needs it (403 otherwise). */
    public bool $clientMayLift = true;
    /** Finish sealed send jobs and validation jobs when they are read (a catto-mail that works instantly). */
    public bool $finishOnRead = false;
    /** @var list<string> registered sender domains */
    public array $senderDomains = ['ctnlist.test', 'smarthost-dev.test'];
    /** @var array<string, array{status?: string, dkim_status?: string, can_send?: bool}> a registered domain that is not (yet) verified and able to send */
    public array $domainStates = [];
    /** @var array<string, array<string, mixed>> connection id => the connection (its code, key and secret included) */
    public array $connections = [];
    /** The client approving a connection, as the exchange names it. */
    public string $clientName = 'Example Ltd';
    /** Why the next exchange is refused with 409 connect-refused (the client's limits or status); null: accepted. */
    public ?string $refuseExchange = null;
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
        $this->requests[] = ['method' => $method, 'path' => $path, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $fault = $this->takeFault($method . ' ' . $path);
        if ($fault !== null && !$fault['after']) {
            return $this->faultResponse($fault['kind']);
        }
        // catto-mail checks the body size before routing or authentication.
        if (isset($options['body']) && is_string($options['body']) && strlen($options['body']) > \App\CattoMail\CattoMailConfig::MAX_REQUEST_BYTES) {
            return self::problem(413, 'payload-too-large', sprintf('Request bodies are limited to %d bytes.', \App\CattoMail\CattoMailConfig::MAX_REQUEST_BYTES));
        }
        // The one operation without an API key: the code and its verifier authorise it.
        if ($method === 'POST' && $path === '/connect/exchange') {
            $response = $this->exchange(is_array($body) ? $body : []);
            return $fault !== null ? $this->faultResponse($fault['kind']) : $response;
        }
        $this->key = str_starts_with($headers['authorization'] ?? '', 'Bearer ') ? substr($headers['authorization'], 7) : '';
        if ($this->key !== self::KEY && $this->connectionOf($this->key) === null) {
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

    /** @return list<array{method: string, path: string, url: string, headers: array<string, string>, body: mixed}> */
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

    /** @return array<string, mixed>|null */
    private function finishSendJobOnRead(string $id): ?array
    {
        if ($this->finishOnRead && ($this->sendJobs[$id]['status'] ?? '') === 'queued') {
            $this->deliver($id);
        }
        return $this->sendJobs[$id] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function finishValidationOnRead(string $id): ?array
    {
        if ($this->finishOnRead && in_array($this->validationJobs[$id]['status'] ?? '', ['queued', 'processing'], true)) {
            $this->completeValidation($id);
        }
        return $this->validationJobs[$id] ?? null;
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
            $method === 'GET' && preg_match("#^/validation-jobs/$id$#", $path, $m) === 1 => $this->found($this->finishValidationOnRead($m[1])),
            $method === 'GET' && preg_match("#^/validation-jobs/$id/addresses$#", $path, $m) === 1 => $this->page($this->validationAddresses[$m[1]] ?? null),
            $method === 'POST' && $path === '/send-jobs' => $this->idempotent('send-jobs', $headers, $body, fn() => $this->createSendJob($body)),
            $method === 'POST' && preg_match("#^/send-jobs/$id/recipients$#", $path, $m) === 1 => $this->idempotent('batch:' . $m[1], $headers, $body, fn() => $this->addBatch($m[1], $body), replayResult: true),
            $method === 'POST' && preg_match("#^/send-jobs/$id/submit$#", $path, $m) === 1 => $this->submit($m[1]),
            $method === 'GET' && preg_match("#^/send-jobs/$id$#", $path, $m) === 1 => $this->found($this->finishSendJobOnRead($m[1])),
            $method === 'GET' && preg_match("#^/send-jobs/$id/messages$#", $path, $m) === 1 => $this->page(isset($this->sendJobs[$m[1]]) ? ($this->messages[$m[1]] ?? []) : null),
            $method === 'GET' && preg_match("#^/messages/$id/events$#", $path, $m) === 1 => $this->page($this->events($m[1])),
            $method === 'POST' && $path === '/global-suppressions' => $this->optOutCapability
                ? $this->idempotent('optout', $headers, $body, fn() => $this->createOptOut($body), replayResult: true)
                : self::problem(403, 'forbidden', 'This client is not permitted to report recipient global opt-outs (an operator grants this capability).'),
            $method === 'GET' && preg_match("#^/global-suppressions/$id$#", $path, $m) === 1 => $this->found($this->optOuts[$m[1]] ?? null),
            $method === 'POST' && preg_match("#^/global-suppressions/$id/lift$#", $path, $m) === 1 => $this->lift($m[1]),
            $method === 'GET' && $path === '/blocks' => $this->listBlocks(),
            $method === 'POST' && $path === '/blocks' => $this->idempotent('blocks', $headers, $body, fn() => $this->createBlock($body), replayResult: true),
            $method === 'GET' && preg_match("#^/blocks/$id$#", $path, $m) === 1 => $this->found($this->blocks[$m[1]] ?? null),
            $method === 'POST' && preg_match("#^/blocks/$id/lift$#", $path, $m) === 1 => $this->liftBlock($m[1]),
            $method === 'DELETE' && $path === '/connection' => $this->endOwnConnection(),
            $method === 'GET' && $path === '/sending-domains' => new MockResponse((string) json_encode(['data' => $this->sendingDomains()]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]),
            default => self::problem(404, 'not-found', 'No such endpoint.'),
        };
    }

    // ---- connect flow ----------------------------------------------------------

    /** The API key of the current request (after authentication). */
    private string $key = '';

    /**
     * What catto-mail's connect page does when a client admin presses Connect
     * for the request in $connectUrl (the address ctnlist sent the browser
     * to): the parameters are checked as that page checks them, and the
     * browser is sent back to the redirect URI with a single-use code and the
     * state. Returns that address.
     */
    public function approve(string $connectUrl): string
    {
        $request = $this->connectRequest($connectUrl);
        // Approving adds the domain the application sends from, when the client does not have it: added, not yet verified.
        $domain = $request['sending_domain'];
        if ($domain !== '' && !in_array($domain, $this->senderDomains, true) && !isset($this->domainStates[$domain])) {
            $this->domainStates[$domain] = ['status' => 'pending', 'dkim_status' => 'not_configured', 'can_send' => false];
        }
        $id = $this->uuid();
        $code = rtrim(strtr(base64_encode(hash('sha256', 'code|' . $id, true)), '+/', '-_'), '=');
        $this->connections[$id] = ['id' => $id, 'status' => 'pending', 'code' => $code, 'expired' => false, 'api_key' => null, 'signing_secret' => null,
            'endpoint_id' => null] + $request;
        return $request['redirect_uri'] . (str_contains($request['redirect_uri'], '?') ? '&' : '?') . http_build_query(['code' => $code, 'state' => $request['state']], '', '&', PHP_QUERY_RFC3986);
    }

    /** The person pressed Decline: back to the redirect URI with error=access_denied and the state. */
    public function decline(string $connectUrl): string
    {
        $request = $this->connectRequest($connectUrl);
        return $request['redirect_uri'] . (str_contains($request['redirect_uri'], '?') ? '&' : '?') . http_build_query(['error' => 'access_denied',
            'error_description' => 'The request was declined.', 'state' => $request['state']], '', '&', PHP_QUERY_RFC3986);
    }

    /** Every code issued so far is now older than its 10 minutes. */
    public function expireCodes(): void
    {
        foreach ($this->connections as &$connection) {
            $connection['expired'] = true;
        }
    }

    /** A client admin (or an operator) ends the connection in catto-mail's dashboard. */
    public function revokeConnection(string $id): void
    {
        $this->connections[$id]['status'] = 'revoked';
    }

    public function lastConnectionId(): string
    {
        return (string) array_key_last($this->connections);
    }

    /**
     * The connect page's parameter rules (integration guide, section 15). A
     * request the page would answer with 400 is a defect in ctnlist, so it
     * fails the test instead of sending the browser anywhere.
     *
     * @return array{application_name: string, redirect_uri: string, webhook_url: string, state: string, code_challenge: string, sending_domain: string}
     */
    private function connectRequest(string $connectUrl): array
    {
        $refuse = static fn(string $why): never => throw new \LogicException('catto-mail\'s connect page would refuse this request (400): ' . $why);
        if (!str_starts_with($connectUrl, self::ORIGIN . '/dashboard/connect?')) {
            $refuse('it is not the connect page of ' . self::ORIGIN . ': ' . $connectUrl);
        }
        parse_str((string) parse_url($connectUrl, PHP_URL_QUERY), $query);
        $text = static fn(string $name): string => is_string($query[$name] ?? null) ? $query[$name] : '';
        if (array_diff(array_keys($query), ['application_name', 'redirect_uri', 'webhook_url', 'state', 'code_challenge', 'code_challenge_method', 'sending_domain']) !== []) {
            $refuse('unknown parameters');
        }
        if (mb_strlen(trim($text('application_name'))) < 1 || mb_strlen($text('application_name')) > 100) {
            $refuse('application_name must be 1 to 100 characters');
        }
        foreach (['redirect_uri', 'webhook_url'] as $name) {
            $parts = parse_url($text($name));
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['fragment']) || strlen($text($name)) > 2000) {
                $refuse($name . ' must be an absolute https URL without credentials or a fragment, at most 2000 characters');
            }
        }
        if (preg_match('/^[\x21-\x7E]{1,512}$/', $text('state')) !== 1) {
            $refuse('state must be 1 to 512 printable ASCII characters without spaces');
        }
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $text('code_challenge')) !== 1 || $text('code_challenge_method') !== 'S256') {
            $refuse('code_challenge must be 43 base64url characters with code_challenge_method=S256');
        }
        if (isset($query['sending_domain']) && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $text('sending_domain')) !== 1) {
            $refuse('sending_domain, when given, must be a domain name');
        }
        return ['application_name' => trim($text('application_name')), 'redirect_uri' => $text('redirect_uri'), 'webhook_url' => $text('webhook_url'),
            'state' => $text('state'), 'code_challenge' => $text('code_challenge'), 'sending_domain' => $text('sending_domain')];
    }

    /** @param array<string, mixed> $body */
    private function exchange(array $body): MockResponse
    {
        foreach (['code', 'code_verifier', 'redirect_uri'] as $name) {
            if (!is_string($body[$name] ?? null) || $body[$name] === '') {
                return new MockResponse((string) json_encode(self::problemBody(422, 'validation-error', 'The request body is invalid.', [['pointer' => '/' . $name, 'message' => 'Required.']])),
                    ['http_code' => 422, 'response_headers' => ['Content-Type' => 'application/problem+json']]);
            }
        }
        $refused = self::problem(400, 'invalid-connect-code', 'The code is not valid: it is unknown, used, expired or was not issued for this request.');
        $id = null;
        foreach ($this->connections as $connection) {
            if (hash_equals((string) $connection['code'], $body['code'])) {
                $id = (string) $connection['id'];
            }
        }
        if ($id === null) {
            return $refused;
        }
        $connection = $this->connections[$id];
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $body['code_verifier'], true)), '+/', '-_'), '=');
        if ($connection['status'] !== 'pending' || $connection['expired'] || $connection['redirect_uri'] !== $body['redirect_uri'] || !hash_equals((string) $connection['code_challenge'], $challenge)) {
            return $refused;
        }
        if ($this->refuseExchange !== null) {
            // Nothing is created, and the code stays usable until it expires.
            return self::problem(409, 'connect-refused', $this->refuseExchange);
        }
        // An earlier connection of this client to the same webhook URL is replaced.
        foreach ($this->connections as $other => $earlier) {
            if ($earlier['status'] === 'active' && $earlier['webhook_url'] === $connection['webhook_url']) {
                $this->connections[$other]['status'] = 'revoked';
            }
        }
        $n = count($this->connections);
        $this->connections[$id] = ['status' => 'active', 'api_key' => 'shk_connect_' . $n . '_' . substr(hash('sha256', $id), 0, 24),
            'signing_secret' => 'whsec_connect_' . $n . '_' . substr(hash('sha256', 'secret|' . $id), 0, 24), 'endpoint_id' => $this->uuid()] + $connection;
        $connection = $this->connections[$id];
        return new MockResponse((string) json_encode([
            'connection_id' => $id, 'application_name' => $connection['application_name'], 'client_name' => $this->clientName, 'api_base_url' => self::BASE,
            'api_key' => $connection['api_key'],
            'webhook' => ['endpoint_id' => $connection['endpoint_id'], 'url' => $connection['webhook_url'], 'signing_secret' => $connection['signing_secret'], 'events' => self::CONNECT_EVENTS],
            'sending_domains' => $this->sendingDomains(),
        ]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']]);
    }

    /** DELETE /v1/connection: the connection of the key that asks; 404 for a key that no connection issued. */
    private function endOwnConnection(): MockResponse
    {
        $id = $this->connectionOf($this->key);
        if ($id === null) {
            return self::problem(404, 'not-found', 'This API key was not issued by an application connection.');
        }
        $this->connections[$id]['status'] = 'revoked';
        return new MockResponse('', ['http_code' => 204]);
    }

    /** The active connection that issued $key, if any. */
    private function connectionOf(string $key): ?string
    {
        foreach ($this->connections as $id => $connection) {
            if ($key !== '' && $connection['status'] === 'active' && $connection['api_key'] === $key) {
                return (string) $id;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> the client's SendingDomain objects */
    private function sendingDomains(): array
    {
        $domains = [];
        foreach (array_values(array_unique([...$this->senderDomains, ...array_keys($this->domainStates)])) as $n => $domain) {
            $state = $this->domainStates[$domain] ?? [];
            $verified = ($state['status'] ?? 'verified') === 'verified';
            $id = sprintf('0199b000-0000-7000-8000-%012x', $n + 1);
            $domains[] = ['id' => $id, 'domain' => $domain, 'status' => $state['status'] ?? 'verified',
                'dkim_status' => $state['dkim_status'] ?? 'active', 'verified_at' => $verified ? '2026-10-06T09:00:00.000000+02:00' : null,
                'can_send' => $state['can_send'] ?? $verified,
                'setup_url' => self::ORIGIN . '/dashboard/c/0199a000-0000-7000-8000-000000000001/sending-domains/' . $id];
        }
        return $domains;
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
            // As catto-mail answers: the reason is in the field errors, not in the detail.
            return [422, self::problemBody(422, 'validation-error', 'The send job cannot be created.',
                [['pointer' => '/sender_identity/email', 'message' => 'The sender domain is not a registered, enabled sending domain of this client.']])];
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
            return [422, self::problemBody(422, 'batch-too-large', 'A batch holds 1 to 500 recipients.', [['pointer' => '/recipients', 'message' => sprintf('Contains %d recipients.', is_array($batch) ? count($batch) : 0)]])];
        }
        if (count($this->recipients[$jobId]) + count($batch) > 10000) {
            return [422, self::problemBody(422, 'recipient-limit-exceeded', 'The job would exceed 10000 recipients.')];
        }
        $seen = $this->addresses[$jobId] ?? [];
        foreach ($batch as $n => $recipient) {
            $address = self::normalise((string) ($recipient['email_address'] ?? ''));
            if (isset($seen[$address])) {
                return [422, self::problemBody(422, 'validation-error', 'The batch was rejected as a whole.',
                    [['pointer' => "/recipients/$n/email_address", 'message' => 'Duplicate of a recipient in an earlier batch of this job (after normalisation).']])];
            }
            $seen[$address] = true;
            $unsubscribe = $recipient['unsubscribe_url'] ?? null;
            if ($job['message_class'] === 'subscription' && (!is_string($unsubscribe) || !str_starts_with($unsubscribe, 'https://'))) {
                return [422, self::problemBody(422, 'validation-error', 'The batch was rejected as a whole.', [['pointer' => "/recipients/$n/unsubscribe_url", 'message' => 'Required for subscription jobs.']])];
            }
            if ($job['message_class'] === 'transactional' && $unsubscribe !== null) {
                return [422, self::problemBody(422, 'validation-error', 'The batch was rejected as a whole.', [['pointer' => "/recipients/$n/unsubscribe_url", 'message' => 'Not accepted for transactional jobs.']])];
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
        } elseif (in_array($job['status'], ['failed', 'cancelled'], true)) {
            return self::problem(409, 'job-not-submittable', 'A ' . $job['status'] . ' job cannot be submitted.');
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
            return self::problem(403, 'forbidden', 'This client is not permitted to report recipient global opt-outs (an operator grants this capability).');
        }
        if (!isset($this->optOuts[$id])) {
            return self::problem(404, 'not-found', 'Unknown opt-out.');
        }
        $this->optOuts[$id]['status'] = 'lifted';
        $this->optOuts[$id]['lifted_at'] ??= '2026-10-06T11:00:00Z';
        return new MockResponse((string) json_encode($this->optOuts[$id]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
    }

    /**
     * POST /v1/blocks: exactly one of address and domain (the schema allows nothing else);
     * the value normalised like catto-mail does; 200 with the active block of the same value.
     *
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function createBlock(array $body): array
    {
        if (array_diff(array_keys($body), ['address', 'domain', 'external_reference']) !== [] || isset($body['address']) === isset($body['domain'])) {
            return [422, self::problemBody(422, 'validation-error', 'Give exactly one of address and domain.')];
        }
        if (isset($body['address'])) {
            $value = self::normalise((string) $body['address']);
            if (preg_match('/^[^@\s]+@[a-z0-9.-]+\.[a-z]{2,}$/', $value) !== 1) {
                return [422, self::problemBody(422, 'validation-error', 'Not a usable email address.')];
            }
            $scope = 'address';
        } else {
            $value = strtolower(rtrim(trim((string) $body['domain']), '.'));
            if (preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $value) !== 1) {
                return [422, self::problemBody(422, 'validation-error', 'Not a domain name such as example.org.')];
            }
            $scope = 'domain';
        }
        foreach ($this->blocks as $block) {
            if ($block['scope'] === $scope && $block['value'] === $value && $block['status'] === 'active') {
                return [200, $block];
            }
        }
        $id = $this->uuid();
        $this->blocks[$id] = ['id' => $id, 'scope' => $scope, 'value' => $value, 'status' => 'active',
            'external_reference' => $body['external_reference'] ?? null, 'created_at' => '2026-10-27T21:00:00+02:00', 'lifted_at' => null];
        return [201, $this->blocks[$id]];
    }

    /** GET /v1/blocks: the account's own blocks, newest first, filtered by status (default active) and exact value. */
    private function listBlocks(): MockResponse
    {
        $status = (string) ($this->lastQuery['status'] ?? 'active');
        if (!in_array($status, ['active', 'lifted', 'all'], true)) {
            return self::problem(400, 'invalid-query-parameter', 'status must be active, lifted or all.');
        }
        $value = isset($this->lastQuery['value']) ? (string) $this->lastQuery['value'] : null;
        $blocks = array_values(array_filter(array_reverse($this->blocks), static fn(array $b): bool => ($status === 'all' || $b['status'] === $status)
            && ($value === null || $b['value'] === (str_contains($value, '@') ? self::normalise($value) : strtolower($value)))));
        return $this->page($blocks);
    }

    /** POST /v1/blocks/{id}/lift: naturally idempotent; needs an active or throttled client. */
    private function liftBlock(string $id): MockResponse
    {
        if (!isset($this->blocks[$id])) {
            return self::problem(404, 'not-found', 'The resource does not exist.');
        }
        if (!$this->clientMayLift) {
            return self::problem(403, 'forbidden', 'An account that is "suspended" may not lift a block: lifting makes mail sendable again.');
        }
        $this->blocks[$id]['status'] = 'lifted';
        $this->blocks[$id]['lifted_at'] ??= '2026-10-27T22:00:00+02:00';
        return new MockResponse((string) json_encode($this->blocks[$id]), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']]);
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
