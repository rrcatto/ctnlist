<?php

declare(strict_types=1);

namespace App\Repository;

use App\CattoMail\IdempotencyKey;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Explicit recipient global opt-outs reported to catto-mail
 * (cattomail_global_optouts), each with its persisted Idempotency-Key.
 *
 * @phpstan-type OptOut array{cgo_id: int, cgo_uuid: string, cgo_s_id: ?int, cgo_email: string, cgo_idempotency_key: string,
 *     cgo_remote_id: ?string, cgo_status: string, cgo_requested_at: string, cgo_error: ?string}
 */
final class CattoMailOptOutRepository
{
    private const COLUMNS = 'cgo_id, cgo_uuid, cgo_s_id, cgo_email, cgo_idempotency_key, cgo_remote_id, cgo_status, cgo_requested_at, cgo_error';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return OptOut */
    public function create(int $subscriberId, string $email): array
    {
        // A concurrent request for the same subscriber (double submit) finds the one already open.
        $id = $this->db->fetchOne(
            "INSERT INTO cattomail_global_optouts (cgo_s_id, cgo_email, cgo_idempotency_key, cgo_requested_at) VALUES (?, ?, ?, ?)
             ON CONFLICT (cgo_s_id) WHERE cgo_status IN ('pending', 'active', 'lift_pending') DO NOTHING RETURNING cgo_id",
            [$subscriberId, $email, IdempotencyKey::generate(), $this->now()]
        );
        if ($id === false) {
            return $this->currentForSubscriber($subscriberId) ?? throw new \LogicException('Opt-out not stored.');
        }
        return $this->find((int) $id) ?? throw new \LogicException('Opt-out not stored.');
    }

    /** @return OptOut|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM cattomail_global_optouts WHERE cgo_id = ?', [$id]);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return OptOut|null the subscriber's opt-out that is pending, active or being lifted */
    public function currentForSubscriber(int $subscriberId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT ' . self::COLUMNS . " FROM cattomail_global_optouts WHERE cgo_s_id = ? AND cgo_status IN ('pending', 'active', 'lift_pending')
             ORDER BY cgo_id DESC LIMIT 1",
            [$subscriberId]
        );
        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<OptOut> opt-outs and lifts still to be reported */
    public function unreported(int $limit = 50): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . " FROM cattomail_global_optouts WHERE cgo_status IN ('pending', 'lift_pending') ORDER BY cgo_id LIMIT " . max(1, $limit)
        ));
    }

    public function markActive(int $id, string $remoteId): void
    {
        $this->db->executeStatement(
            "UPDATE cattomail_global_optouts SET cgo_remote_id = ?, cgo_status = CASE WHEN cgo_status = 'pending' THEN 'active' ELSE cgo_status END,
                cgo_confirmed_at = COALESCE(cgo_confirmed_at, ?), cgo_error = NULL WHERE cgo_id = ?",
            [strtolower($remoteId), $this->now(), $id]
        );
    }

    public function requestLift(int $id): void
    {
        $this->db->executeStatement("UPDATE cattomail_global_optouts SET cgo_status = 'lift_pending', cgo_lift_requested_at = ? WHERE cgo_id = ? AND cgo_status IN ('pending', 'active')",
            [$this->now(), $id]);
    }

    public function markLifted(int $id): void
    {
        $this->db->executeStatement("UPDATE cattomail_global_optouts SET cgo_status = 'lifted', cgo_lifted_at = ?, cgo_error = NULL WHERE cgo_id = ?", [$this->now(), $id]);
    }

    public function markRejected(int $id, string $error): void
    {
        $this->db->executeStatement("UPDATE cattomail_global_optouts SET cgo_status = 'rejected', cgo_error = ? WHERE cgo_id = ?", [mb_substr($error, 0, 2000), $id]);
    }

    public function keepActive(int $id, string $error): void
    {
        $this->db->executeStatement("UPDATE cattomail_global_optouts SET cgo_status = 'active', cgo_lift_requested_at = NULL, cgo_error = ? WHERE cgo_id = ?", [mb_substr($error, 0, 2000), $id]);
    }

    public function noteAttempt(int $id, string $error): void
    {
        $this->db->executeStatement('UPDATE cattomail_global_optouts SET cgo_attempts = cgo_attempts + 1, cgo_error = ? WHERE cgo_id = ?', [mb_substr($error, 0, 2000), $id]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     * @return OptOut
     */
    private static function hydrate(array $row): array
    {
        return [
            'cgo_id' => (int) $row['cgo_id'], 'cgo_uuid' => (string) $row['cgo_uuid'], 'cgo_s_id' => $row['cgo_s_id'] === null ? null : (int) $row['cgo_s_id'],
            'cgo_email' => (string) $row['cgo_email'], 'cgo_idempotency_key' => (string) $row['cgo_idempotency_key'],
            'cgo_remote_id' => $row['cgo_remote_id'] === null ? null : (string) $row['cgo_remote_id'], 'cgo_status' => (string) $row['cgo_status'],
            'cgo_requested_at' => (string) $row['cgo_requested_at'], 'cgo_error' => $row['cgo_error'] === null ? null : (string) $row['cgo_error'],
        ];
    }
}
