<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Repository\OptionRepository;
use Psr\Clock\ClockInterface;

/**
 * "Can ctnlist talk to catto-mail right now?", for the integration status
 * page: configuration first, then CattoMailClient::probe() (one harmless
 * authenticated read, no work created), classified so the administrator
 * knows what to fix. The last result is kept so the page can show it
 * without calling catto-mail on every view. Messages never contain secrets.
 */
final class CattoMailHealth
{
    public const OK = 'ok';
    private const KEY = 'cattomail:health';

    /** state => [Bootstrap colour, short label, what to do] */
    public const STATES = [
        'ok' => ['success', 'Reachable and authenticated', 'catto-mail answered with this client\'s API key.'],
        'not_configured' => ['secondary', 'Not configured', 'Set CATTOMAIL_API_BASE_URL and CATTOMAIL_API_KEY in the installation\'s .env.'],
        'dns' => ['danger', 'Host name not found', 'The API host does not resolve from this server (check CATTOMAIL_API_BASE_URL, or in development CATTOMAIL_API_CONNECT_HOST and the shared network).'],
        'connection' => ['danger', 'Cannot connect', 'Nothing answered on the API address (catto-mail down, firewall, or the development network not attached).'],
        'tls' => ['danger', 'TLS failure', 'The certificate was not trusted or the TLS handshake failed (in development check CATTOMAIL_CA_FILE).'],
        'auth_rejected' => ['danger', 'API key rejected', 'catto-mail refused the API key: check CATTOMAIL_API_KEY (revoked, mistyped or for another installation).'],
        'forbidden' => ['warning', 'Client not permitted', 'The key is valid but the client may not use the API (ask the catto-mail operator).'],
        'unavailable' => ['warning', 'Temporarily unavailable', 'catto-mail answered with a temporary error or rate limit; try again shortly.'],
        'unexpected' => ['warning', 'Unexpected answer', 'catto-mail answered, but not as the API contract describes; see the detail.'],
    ];

    public function __construct(
        private readonly CattoMailClient $client,
        private readonly OptionRepository $options,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return array{state: string, detail: string, checked_at: string} */
    public function check(): array
    {
        $probe = $this->client->probe();
        $status = $probe['status'];
        $state = match (true) {
            $probe['failure'] !== null => $probe['failure'],
            $status === 404 => self::OK,
            $status === 401 => 'auth_rejected',
            $status === 403 => 'forbidden',
            $status === 429, $status !== null && $status >= 500 => 'unavailable',
            default => 'unexpected',
        };
        $result = [
            'state' => $state,
            'detail' => $probe['error'] ?? ('HTTP ' . $status . ' from GET /v1/send-jobs/' . CattoMailClient::PROBE_ID),
            'checked_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
        $this->options->set(self::KEY, (string) json_encode($result));
        return $result;
    }

    /** @return array{state: string, detail: string, checked_at: string}|null the last check, if any */
    public function last(): ?array
    {
        $stored = json_decode((string) $this->options->get(self::KEY), true);
        if (!is_array($stored) || !isset(self::STATES[$stored['state'] ?? ''])) {
            return null;
        }
        return ['state' => (string) $stored['state'], 'detail' => (string) ($stored['detail'] ?? ''), 'checked_at' => (string) ($stored['checked_at'] ?? '')];
    }
}
