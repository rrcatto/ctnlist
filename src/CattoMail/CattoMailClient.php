<?php

declare(strict_types=1);

namespace App\CattoMail;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The only component that talks to catto-mail: its public HTTPS /v1 API,
 * with the API key as a Bearer token. Every work-creating call takes the
 * caller's persisted Idempotency-Key, so a retry (here or in a later run) is
 * the same logical operation. Retryable failures are retried a few times in
 * place with the same key; what remains surfaces as CattoMailUnavailable.
 * Errors and log entries never contain the key or the Authorization header.
 */
final class CattoMailClient
{
    /** In-place attempts for a retryable failure (the first try included). */
    private const ATTEMPTS = 3;
    private const RETRY_AFTER_CAP = 10;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CattoMailConfig $config,
        private readonly LoggerInterface $logger,
        ?\Closure $sleep = null,
        private readonly ?CattoMailActivity $activity = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    // ---- validation ---------------------------------------------------------

    /**
     * @param list<array{address: string, external_address_reference?: string}> $addresses
     * @return array<string, mixed> the ValidationJob
     */
    public function createValidationJob(string $idempotencyKey, string $externalReference, array $addresses): array
    {
        return $this->request('POST', '/validation-jobs', ['external_reference' => $externalReference, 'addresses' => $addresses], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function getValidationJob(string $id): array
    {
        return $this->request('GET', '/validation-jobs/' . self::id($id));
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function listValidationAddresses(string $id, ?string $cursor = null, int $limit = 1000): array
    {
        return self::page($this->request('GET', '/validation-jobs/' . self::id($id) . '/addresses', query: self::paging($cursor, $limit)));
    }

    // ---- sending ------------------------------------------------------------

    /**
     * @param array<string, mixed> $job SendJobCreateRequest
     * @return array<string, mixed> the SendJob
     */
    public function createSendJob(string $idempotencyKey, array $job): array
    {
        return $this->request('POST', '/send-jobs', $job, $idempotencyKey);
    }

    /**
     * @param list<array<string, mixed>> $recipients at most 500 rendered recipients
     * @return array<string, mixed> the RecipientBatchResult
     */
    public function addRecipients(string $sendJobId, string $idempotencyKey, array $recipients): array
    {
        if ($recipients === [] || count($recipients) > CattoMailConfig::MAX_BATCH_RECIPIENTS) {
            throw new \InvalidArgumentException('A recipient batch holds 1 to ' . CattoMailConfig::MAX_BATCH_RECIPIENTS . ' recipients.');
        }
        return $this->request('POST', '/send-jobs/' . self::id($sendJobId) . '/recipients', ['recipients' => $recipients], $idempotencyKey);
    }

    /**
     * The size of $body as sent: Symfony HttpClient's `json` option encodes with these flags, which turn
     * every <, >, &, ' and " into a six-byte \u escape (HTML grows by about half).
     *
     * @param array<mixed> $body
     */
    public static function jsonSize(array $body): int
    {
        return strlen(json_encode($body, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /**
     * Seal and queue the job; naturally idempotent (a sealed job returns its state).
     *
     * @return array<string, mixed> the SendJob
     */
    public function submitSendJob(string $sendJobId): array
    {
        return $this->request('POST', '/send-jobs/' . self::id($sendJobId) . '/submit');
    }

    /** @return array<string, mixed> */
    public function getSendJob(string $sendJobId): array
    {
        return $this->request('GET', '/send-jobs/' . self::id($sendJobId));
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function listSendJobMessages(string $sendJobId, ?string $cursor = null, int $limit = 1000): array
    {
        return self::page($this->request('GET', '/send-jobs/' . self::id($sendJobId) . '/messages', query: self::paging($cursor, $limit)));
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function listMessageEvents(string $messageId, ?string $cursor = null, int $limit = 100): array
    {
        return self::page($this->request('GET', '/messages/' . self::id($messageId) . '/events', query: self::paging($cursor, $limit)));
    }

    // ---- explicit global opt-out (needs the operator-granted capability) ----

    /** @return array<string, mixed> the GlobalOptOut */
    public function createGlobalOptOut(string $idempotencyKey, string $email, string $externalReference): array
    {
        return $this->request('POST', '/global-suppressions', ['email_address' => $email, 'external_reference' => $externalReference], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function getGlobalOptOut(string $id): array
    {
        return $this->request('GET', '/global-suppressions/' . self::id($id));
    }

    /**
     * Naturally idempotent.
     *
     * @return array<string, mixed>
     */
    public function liftGlobalOptOut(string $id): array
    {
        return $this->request('POST', '/global-suppressions/' . self::id($id) . '/lift');
    }

    // ---- connection (catto-mail's connect flow; integration guide, section 15) ----

    /**
     * Exchange the single-use code of an approved connect request, at the
     * mail service the administrator named. The one request without an API
     * key: the answer hands over the key and the webhook signing secret, once.
     *
     * @param string $serviceOrigin https://host[:port] of the mail service
     * @return array<string, mixed> ConnectCredentials
     */
    public function exchangeConnectCode(string $serviceOrigin, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $codeVerifier, string $redirectUri): array
    {
        return $this->request('POST', '/connect/exchange', ['code' => $code, 'code_verifier' => $codeVerifier, 'redirect_uri' => $redirectUri],
            root: rtrim($serviceOrigin, '/') . '/v1', apiKey: '', secrets: [$code, $codeVerifier]);
    }

    /**
     * End the connection that issued $apiKey: catto-mail revokes the key and
     * removes the webhook endpoint (DELETE /v1/connection, 204). 401 means the
     * key is no longer accepted, 404 that no connection issued it.
     */
    public function deleteConnection(string $apiRoot, #[\SensitiveParameter] string $apiKey): void
    {
        $this->request('DELETE', '/connection', root: $apiRoot, apiKey: $apiKey, secrets: [$apiKey]);
    }

    /**
     * The client's sending domains and whether each can send now.
     *
     * @return list<array{domain: string, status: string, dkim_status: string, can_send: bool}>
     */
    public function listSendingDomains(): array
    {
        return self::sendingDomains($this->request('GET', '/sending-domains')['data'] ?? null);
    }

    /**
     * The SendingDomain items of an API answer, reduced to what ctnlist reads.
     *
     * @return list<array{domain: string, status: string, dkim_status: string, can_send: bool}>
     */
    public static function sendingDomains(mixed $items): array
    {
        $domains = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['domain'] ?? null) && $item['domain'] !== '') {
                $domains[] = ['domain' => strtolower($item['domain']), 'status' => (string) ($item['status'] ?? ''), 'dkim_status' => (string) ($item['dkim_status'] ?? ''),
                    'can_send' => ($item['can_send'] ?? false) === true];
            }
        }
        return $domains;
    }

    // ---- health ---------------------------------------------------------------

    /** A send-job id that cannot exist (catto-mail ids are UUIDv7 generated by catto-mail). */
    public const PROBE_ID = '00000000-0000-7000-8000-000000000000';

    /**
     * The least invasive authenticated request the contract offers (it has
     * no health endpoint): read a send job that cannot exist. One attempt, no
     * retries, nothing created. 404 proves network, TLS and the API key; 401
     * means the key was refused.
     *
     * @return array{status: ?int, failure: ?string, error: ?string} failure: dns, tls or connection when no HTTP answer came back
     */
    public function probe(): array
    {
        if ($this->config->problem() !== null) {
            return ['status' => null, 'failure' => 'not_configured', 'error' => $this->config->problem()];
        }
        [$connectHost] = self::connectTarget($this->config->connectHost);
        if ($connectHost !== '' && filter_var($connectHost, FILTER_VALIDATE_IP) === false && gethostbyname($connectHost) === $connectHost) {
            return ['status' => null, 'failure' => 'dns', 'error' => 'CATTOMAIL_API_CONNECT_HOST (' . $connectHost . ') does not resolve.'];
        }
        try {
            [$url, $options] = $this->routed($this->config->apiRoot() . '/send-jobs/' . self::PROBE_ID, $this->options(null, null, []));
            $response = $this->http->request('GET', $url, $options);
            $status = $response->getStatusCode();
            if ($status === 404) {
                $this->activity?->apiSucceeded();
            }
            return ['status' => $status, 'failure' => null, 'error' => null];
        } catch (TransportExceptionInterface $e) {
            $message = $this->redact($e->getMessage());
            return ['status' => null, 'failure' => self::transportFailure($message), 'error' => $message];
        }
    }

    /** dns, tls or connection, from the HTTP client's (curl's) message. */
    public static function transportFailure(string $message): string
    {
        return match (true) {
            (bool) preg_match('/resolve host|getaddrinfo|name or service not known|could not resolve|no address/i', $message) => 'dns',
            (bool) preg_match('/ssl|tls|certificate|handshake/i', $message) => 'tls',
            default => 'connection',
        };
    }

    // ---- transport ----------------------------------------------------------

    /**
     * $root and $apiKey are given only by the connect operations, which do
     * not use the configured client: another API root, and that request's own
     * key ('' for none). Their outcome is not part of the configured client's
     * activity (last success, last error), and $secrets are kept out of every
     * message.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $query
     * @param list<string> $secrets
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, array $query = [],
        ?string $root = null, #[\SensitiveParameter] ?string $apiKey = null, #[\SensitiveParameter] array $secrets = []): array
    {
        $configured = $root === null;
        [$url, $options] = $this->routed(($root ?? $this->config->apiRoot()) . $path, $this->options($body, $idempotencyKey, $query, $configured ? null : (string) $apiKey));
        $activity = $configured ? $this->activity : null;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $data = $status === 204 ? [] : self::decode($response, $method, $path);
                    $activity?->apiSucceeded();
                    return $data;
                }
                $failure = $this->failure($response, $status, $method, $path, $secrets, $configured);
            } catch (TransportExceptionInterface $e) {
                $detail = $this->redact($e->getMessage(), $secrets);
                $failure = new CattoMailUnavailable(sprintf('catto-mail could not be reached (%s; the work is kept and retried): %s %s: %s',
                    match (self::transportFailure($detail)) { 'dns' => 'host name not found', 'tls' => 'TLS/certificate problem', default => 'connection failed' },
                    $method, $path, $detail));
            }
            if (!$failure instanceof CattoMailUnavailable || $attempt >= self::ATTEMPTS) {
                $this->logger->warning('catto-mail API call failed: {error}', ['error' => $failure->getMessage()]);
                $activity?->apiFailed($failure->getMessage());
                throw $failure;
            }
            ($this->sleep)($this->retryDelay($failure, $attempt));
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $query
     * @param string|null $apiKey null: the configured client's key; otherwise this request's own key ('' for none)
     * @return array<string, mixed>
     */
    private function options(?array $body, ?string $idempotencyKey, array $query, #[\SensitiveParameter] ?string $apiKey = null): array
    {
        if ($apiKey === null) {
            $problem = $this->config->problem();
            if ($problem !== null) {
                throw new CattoMailRejected($problem, 0, 'not-configured');
            }
            $apiKey = $this->config->apiKey();
        }
        $options = [
            'headers' => ['Accept' => 'application/json, application/problem+json'],
            'timeout' => $this->config->timeoutSeconds,
            'max_duration' => $this->config->timeoutSeconds * 2,
            'max_redirects' => 0,
            'query' => $query,
        ];
        if ($apiKey !== '') {
            $options['auth_bearer'] = $apiKey;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($idempotencyKey !== null) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }
        if ($this->config->caFile !== '') {
            $options['cafile'] = $this->config->caFile;
        }
        return $options;
    }

    /**
     * The development route (CATTOMAIL_API_CONNECT_HOST, host[:port]): reach
     * the API host of $url through another name, and, when a port is given,
     * on that port (catto-mail's development address is a published port
     * that exists only on the developer's machine). TLS still checks the API
     * host's certificate, and the request still names the API host.
     *
     * @param array<string, mixed> $options
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function routed(string $url, array $options): array
    {
        [$host, $port] = self::connectTarget($this->config->connectHost);
        $parts = parse_url($url);
        if ($host === '' || !is_array($parts) || !isset($parts['host'])) {
            return [$url, $options];
        }
        $options['resolve'] = [$parts['host'] => filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host)];
        $urlPort = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'http' ? 80 : 443);
        if ($port !== null && $port !== $urlPort) {
            $options['headers']['Host'] = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $url = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . ':' . $port . ($parts['path'] ?? '');
        }
        return [$url, $options];
    }

    /** @return array{0: string, 1: ?int} the host and optional port of CATTOMAIL_API_CONNECT_HOST */
    public static function connectTarget(string $connectHost): array
    {
        $connectHost = trim($connectHost);
        if (preg_match('/^(.+):(\d{1,5})$/', $connectHost, $m) === 1 && filter_var($connectHost, FILTER_VALIDATE_IP) === false) {
            return [trim($m[1], '[]'), (int) $m[2]];
        }
        return [$connectHost, null];
    }

    /** @param list<string> $secrets */
    private function failure(ResponseInterface $response, int $status, string $method, string $path, #[\SensitiveParameter] array $secrets = [], bool $configured = true): CattoMailException
    {
        $problem = [];
        try {
            $problem = $response->toArray(false);
        } catch (\Throwable) {
            // Not a problem+json body.
        }
        $detail = $this->redact(trim((string) ($problem['detail'] ?? $problem['title'] ?? '')), $secrets);
        $message = sprintf('catto-mail %s %s returned %d%s', $method, $path, $status, $detail !== '' ? ': ' . $detail : '');
        $type = (string) ($problem['type'] ?? '');
        $retryable = in_array($status, [408, 425, 429], true) || $status >= 500
            // 409 is retryable only while the same Idempotency-Key is still in progress; otherwise it is a state conflict.
            || ($status === 409 && str_ends_with($type, '/idempotency-in-progress'));
        if ($retryable) {
            $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? '';
            $exception = new CattoMailUnavailable($message, $status);
            $exception->retryAfter = ctype_digit($retryAfter) ? (int) $retryAfter : null;
            return $exception;
        }
        $errors = [];
        foreach (is_array($problem['errors'] ?? null) ? $problem['errors'] : [] as $error) {
            if (is_array($error)) {
                $errors[] = ['pointer' => (string) ($error['pointer'] ?? ''), 'message' => $this->redact((string) ($error['message'] ?? ''), $secrets)];
            }
        }
        // catto-mail often gives the reason only in the field errors (e.g. an unregistered sender domain).
        $fields = implode('; ', array_map(static fn(array $e): string => trim($e['pointer'] . ' ' . $e['message']), array_slice($errors, 0, 3)));
        if ($fields !== '') {
            $message .= ' (' . mb_substr($fields, 0, 400) . ')';
        }
        // The advice is about the configured client's key and job; a connect operation explains itself (ConnectFlow).
        $advice = $configured ? $this->advice($status, $errors) : null;
        return new CattoMailRejected($advice !== null ? $advice . ' ' . $message : $message, $status, $type, $errors);
    }

    /**
     * What an administrator should do about a refusal, in front of the
     * technical message.
     *
     * @param list<array{pointer: string, message: string}> $errors
     */
    private function advice(int $status, array $errors): ?string
    {
        foreach ($errors as $error) {
            if (str_starts_with($error['pointer'], '/sender_identity')) {
                return 'catto-mail does not accept this From address: its domain must be a registered, enabled sending domain of this client in catto-mail (the message sender, or MAIL_FROM_ADDRESS).';
            }
        }
        return match ($status) {
            401 => $this->config->source('api_key') === CattoMailConfig::SOURCE_CONNECTION
                ? 'catto-mail rejected the API key of this connection: it was probably revoked there. Connect again on the Delivery page.'
                : 'catto-mail rejected the API key: check CATTOMAIL_API_KEY in .env (revoked, mistyped or for another installation).',
            403 => 'catto-mail does not permit this client to do that (pending approval, suspended, or a capability the catto-mail operator has not granted).',
            413 => 'The request was larger than catto-mail accepts (10 MiB); the message content is probably too large.',
            default => null,
        };
    }

    private function retryDelay(CattoMailUnavailable $failure, int $attempt): int
    {
        if ($failure->retryAfter !== null) {
            return min(self::RETRY_AFTER_CAP, $failure->retryAfter);
        }
        return min(self::RETRY_AFTER_CAP, 2 ** ($attempt - 1));
    }

    /** @return array<string, mixed> */
    private static function decode(ResponseInterface $response, string $method, string $path): array
    {
        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new CattoMailUnavailable(sprintf('catto-mail %s %s returned an unreadable body.', $method, $path), 0, $e);
        }
        return $data;
    }

    /**
     * Remove the API key, the webhook secrets, any Authorization value and $secrets from a message.
     *
     * @param list<string> $secrets
     */
    public function redact(string $text, #[\SensitiveParameter] array $secrets = []): string
    {
        foreach ([$this->config->apiKey(), ...$this->config->webhookSecrets(), ...$secrets] as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        return (string) preg_replace('/(Authorization:\s*Bearer\s+|Bearer\s+)[^\s",]+/i', '$1[redacted]', $text);
    }

    /** @return array<string, string|int> */
    private static function paging(?string $cursor, int $limit): array
    {
        $query = ['limit' => max(1, min(1000, $limit))];
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        return $query;
    }

    /**
     * @param array<string, mixed> $page
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    private static function page(array $page): array
    {
        $data = array_values(array_filter(is_array($page['data'] ?? null) ? $page['data'] : [], 'is_array'));
        $next = $page['pagination']['next_cursor'] ?? null;
        return ['data' => $data, 'next_cursor' => is_string($next) && $next !== '' ? $next : null];
    }

    /** catto-mail ids are UUIDs; never put anything else into a path. */
    private static function id(string $id): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
            throw new \InvalidArgumentException('Not a catto-mail id.');
        }
        return strtolower($id);
    }
}
