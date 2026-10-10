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
        if ($this->config->connectHost !== '' && filter_var($this->config->connectHost, FILTER_VALIDATE_IP) === false
            && gethostbyname($this->config->connectHost) === $this->config->connectHost) {
            return ['status' => null, 'failure' => 'dns', 'error' => 'CATTOMAIL_API_CONNECT_HOST (' . $this->config->connectHost . ') does not resolve.'];
        }
        try {
            $response = $this->http->request('GET', $this->config->apiRoot() . '/send-jobs/' . self::PROBE_ID, $this->options(null, null, []));
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
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, array $query = []): array
    {
        $options = $this->options($body, $idempotencyKey, $query);
        $url = $this->config->apiRoot() . $path;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $data = self::decode($response, $method, $path);
                    $this->activity?->apiSucceeded();
                    return $data;
                }
                $failure = $this->failure($response, $status, $method, $path);
            } catch (TransportExceptionInterface $e) {
                $detail = $this->redact($e->getMessage());
                $failure = new CattoMailUnavailable(sprintf('catto-mail could not be reached (%s; the work is kept and retried): %s %s: %s',
                    match (self::transportFailure($detail)) { 'dns' => 'host name not found', 'tls' => 'TLS/certificate problem', default => 'connection failed' },
                    $method, $path, $detail));
            }
            if (!$failure instanceof CattoMailUnavailable || $attempt >= self::ATTEMPTS) {
                $this->logger->warning('catto-mail API call failed: {error}', ['error' => $failure->getMessage()]);
                $this->activity?->apiFailed($failure->getMessage());
                throw $failure;
            }
            ($this->sleep)($this->retryDelay($failure, $attempt));
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function options(?array $body, ?string $idempotencyKey, array $query): array
    {
        $problem = $this->config->problem();
        if ($problem !== null) {
            throw new CattoMailRejected($problem, 0, 'not-configured');
        }
        $options = [
            'auth_bearer' => $this->config->apiKey(),
            'headers' => ['Accept' => 'application/json, application/problem+json'],
            'timeout' => $this->config->timeoutSeconds,
            'max_duration' => $this->config->timeoutSeconds * 2,
            'max_redirects' => 0,
            'query' => $query,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($idempotencyKey !== null) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }
        if ($this->config->caFile !== '') {
            $options['cafile'] = $this->config->caFile;
        }
        if ($this->config->connectHost !== '') {
            // Development route: reach the API host through another name; TLS still checks the API host's certificate.
            $address = filter_var($this->config->connectHost, FILTER_VALIDATE_IP) !== false ? $this->config->connectHost : gethostbyname($this->config->connectHost);
            $options['resolve'] = [(string) parse_url($this->config->apiRoot(), PHP_URL_HOST) => $address];
        }
        return $options;
    }

    private function failure(ResponseInterface $response, int $status, string $method, string $path): CattoMailException
    {
        $problem = [];
        try {
            $problem = $response->toArray(false);
        } catch (\Throwable) {
            // Not a problem+json body.
        }
        $detail = $this->redact(trim((string) ($problem['detail'] ?? $problem['title'] ?? '')));
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
                $errors[] = ['pointer' => (string) ($error['pointer'] ?? ''), 'message' => $this->redact((string) ($error['message'] ?? ''))];
            }
        }
        // catto-mail often gives the reason only in the field errors (e.g. an unregistered sender domain).
        $fields = implode('; ', array_map(static fn(array $e): string => trim($e['pointer'] . ' ' . $e['message']), array_slice($errors, 0, 3)));
        if ($fields !== '') {
            $message .= ' (' . mb_substr($fields, 0, 400) . ')';
        }
        $advice = self::advice($status, $errors);
        return new CattoMailRejected($advice !== null ? $advice . ' ' . $message : $message, $status, $type, $errors);
    }

    /**
     * What an administrator should do about a refusal, in front of the
     * technical message.
     *
     * @param list<array{pointer: string, message: string}> $errors
     */
    private static function advice(int $status, array $errors): ?string
    {
        foreach ($errors as $error) {
            if (str_starts_with($error['pointer'], '/sender_identity')) {
                return 'catto-mail does not accept this From address: its domain must be a registered, enabled sending domain of this client in catto-mail (the message sender, or MAIL_FROM_ADDRESS).';
            }
        }
        return match ($status) {
            401 => 'catto-mail rejected the API key: check CATTOMAIL_API_KEY in .env (revoked, mistyped or for another installation).',
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

    /** Remove the API key, the webhook secrets and any Authorization value from a message. */
    public function redact(string $text): string
    {
        foreach ([$this->config->apiKey(), ...$this->config->webhookSecrets()] as $secret) {
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
