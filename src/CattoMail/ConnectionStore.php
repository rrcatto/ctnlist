<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Config\SettingsCipher;
use App\Config\SettingsCipherException;
use App\Repository\SettingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;

/**
 * The connection to the mail service that an administrator made on the
 * Delivery page (ConnectFlow), kept in the Settings store (`options`,
 * `setting:<NAME>`): the API address, and, encrypted with SettingsCipher
 * (APP_SETTINGS_KEY), the API key and the webhook signing secret that
 * catto-mail handed over once. The names are the .env names, because .env
 * supplies the same values and wins where it sets one (CattoMailConfigFactory);
 * they are not in SettingsCatalogue, so the Settings page never shows or
 * changes them. Nothing here is ever logged or rendered except the details
 * (who, when, which service, which catto-mail client), which hold no secret.
 *
 * @phpstan-type ConnectionDetails array{connection_id: string, application_name: string, client_name: string, service: string,
 *     webhook_url: string, webhook_endpoint_id: string, connected_at: string, connected_by: string}
 */
final class ConnectionStore
{
    public const BASE_URL = 'CATTOMAIL_API_BASE_URL';
    public const API_KEY = 'CATTOMAIL_API_KEY';
    public const WEBHOOK_SECRET = 'CATTOMAIL_WEBHOOK_SECRET';
    /** The non-secret details of the connection (JSON). */
    public const DETAILS = 'CATTOMAIL_CONNECTION';

    private const NAMES = [self::BASE_URL, self::API_KEY, self::WEBHOOK_SECRET, self::DETAILS];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly SettingsCipher $cipher,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The stored credentials, decrypted; null when there is no connection.
     * `problem` says why the secrets cannot be read (and they are then '').
     *
     * @return array{base_url: string, api_key: string, webhook_secret: string, problem: ?string}|null
     */
    public function credentials(): ?array
    {
        $stored = $this->stored();
        if (!isset($stored[self::BASE_URL], $stored[self::API_KEY])) {
            return null;
        }
        try {
            return [
                'base_url' => $stored[self::BASE_URL]['value'],
                'api_key' => $this->cipher->decrypt($stored[self::API_KEY]['value']),
                'webhook_secret' => isset($stored[self::WEBHOOK_SECRET]) ? $this->cipher->decrypt($stored[self::WEBHOOK_SECRET]['value']) : '',
                'problem' => null,
            ];
        } catch (SettingsCipherException $e) {
            return ['base_url' => $stored[self::BASE_URL]['value'], 'api_key' => '', 'webhook_secret' => '', 'problem' => $e->getMessage()];
        }
    }

    /**
     * Who connected what, and when; null when there is no connection.
     *
     * @return ConnectionDetails|null
     */
    public function details(): ?array
    {
        $stored = $this->stored();
        if (!isset($stored[self::BASE_URL], $stored[self::API_KEY])) {
            return null;
        }
        $details = json_decode($stored[self::DETAILS]['value'] ?? '', true);
        $details = is_array($details) ? $details : [];
        $text = static fn(string $key): string => is_scalar($details[$key] ?? null) ? (string) $details[$key] : '';
        return [
            'connection_id' => $text('connection_id'),
            'application_name' => $text('application_name'),
            'client_name' => $text('client_name'),
            'service' => $text('service') !== '' ? $text('service') : $stored[self::BASE_URL]['value'],
            'webhook_url' => $text('webhook_url'),
            'webhook_endpoint_id' => $text('webhook_endpoint_id'),
            'connected_at' => (string) ($stored[self::API_KEY]['updated_at'] ?? ''),
            'connected_by' => (string) ($stored[self::API_KEY]['updated_by'] ?? ''),
        ];
    }

    /**
     * Keep what catto-mail handed over (all of it, or nothing).
     *
     * @param array{connection_id: string, application_name: string, client_name: string, service: string, webhook_url: string, webhook_endpoint_id: string} $details
     * @throws SettingsCipherException when APP_SETTINGS_KEY cannot be used
     */
    public function save(string $baseUrl, #[\SensitiveParameter] string $apiKey, #[\SensitiveParameter] string $webhookSecret, array $details, ?int $by): void
    {
        // Encrypt first: nothing is stored unless every secret can be.
        $key = $this->cipher->encrypt($apiKey);
        $secret = $this->cipher->encrypt($webhookSecret);
        $this->db->transactional(function () use ($baseUrl, $key, $secret, $details, $by): void {
            $this->settings->set(self::BASE_URL, $baseUrl, false, $by);
            $this->settings->set(self::API_KEY, $key, true, $by);
            $this->settings->set(self::WEBHOOK_SECRET, $secret, true, $by);
            $this->settings->set(self::DETAILS, (string) json_encode($details, JSON_UNESCAPED_SLASHES), false, $by);
        });
    }

    /** Remove the stored connection; .env (if it sets catto-mail) applies again. */
    public function clear(): void
    {
        $this->db->transactional(function (): void {
            foreach (self::NAMES as $name) {
                $this->settings->delete($name);
            }
        });
    }

    /** @return array<string, array{value: string, secret: bool, updated_at: ?string, updated_by: ?string}> */
    private function stored(): array
    {
        try {
            return array_intersect_key($this->settings->all(), array_flip(self::NAMES));
        } catch (DbalException $e) {
            // Before migrations, or with the database unavailable: .env alone applies.
            $this->logger->warning('The stored catto-mail connection is unavailable: {message}', ['message' => $e->getMessage()]);
            return [];
        }
    }
}
