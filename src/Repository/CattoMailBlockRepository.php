<?php

declare(strict_types=1);

namespace App\Repository;

use App\CattoMail\IdempotencyKey;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * An administrator's blocks of an address or a whole domain, kept by catto-mail as account
 * blocks (cattomail_blocks), each with its persisted Idempotency-Key. A pending or active
 * block stops the address here too; a block being lifted no longer does.
 *
 * @phpstan-type Block array{cbl_id: int, cbl_uuid: string, cbl_scope: 'address'|'domain', cbl_value: string, cbl_idempotency_key: string,
 *     cbl_remote_id: ?string, cbl_status: string, cbl_requested_at: string, cbl_attempts: int, cbl_error: ?string}
 */
final class CattoMailBlockRepository
{
    private const COLUMNS = 'cbl_id, cbl_uuid, cbl_scope, cbl_value, cbl_idempotency_key, cbl_remote_id, cbl_status, cbl_requested_at, cbl_attempts, cbl_error';
    /** The states in which a block stops mail. */
    private const IN_FORCE = "('pending', 'active')";

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return Block the new block, or the one of the same value already open (a double submit finds it) */
    public function create(string $scope, string $value, ?int $adminId): array
    {
        $id = $this->db->fetchOne(
            "INSERT INTO cattomail_blocks (cbl_scope, cbl_value, cbl_idempotency_key, cbl_requested_by_s_id, cbl_requested_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (cbl_scope, lower(cbl_value)) WHERE cbl_status IN ('pending', 'active', 'lift_pending') DO NOTHING RETURNING cbl_id",
            [$scope, $value, IdempotencyKey::generate(), $adminId, $this->now()]
        );
        if ($id === false) {
            return $this->open($scope, $value) ?? throw new \LogicException('Block not stored.');
        }
        return $this->find((int) $id) ?? throw new \LogicException('Block not stored.');
    }

    /** @return Block|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM cattomail_blocks WHERE cbl_id = ?', [$id]);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return Block|null */
    public function findByUuid(string $uuid): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $uuid) !== 1) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM cattomail_blocks WHERE cbl_uuid = ?', [strtolower($uuid)]);
        return $row === false ? null : self::hydrate($row);
    }

    /** @return Block|null the block of this value that is pending, active or being lifted */
    public function open(string $scope, string $value): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT ' . self::COLUMNS . " FROM cattomail_blocks WHERE cbl_scope = ? AND lower(cbl_value) = lower(?)
             AND cbl_status IN ('pending', 'active', 'lift_pending') ORDER BY cbl_id DESC LIMIT 1",
            [$scope, $value]
        );
        return $row === false ? null : self::hydrate($row);
    }

    /**
     * The blocks that stop an address now: its own and its domain's (exactly that domain).
     *
     * @return array{address: Block|null, domain: Block|null}
     */
    public function covering(string $email): array
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return ['address' => null, 'domain' => null];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM cattomail_blocks WHERE cbl_status IN ' . self::IN_FORCE . "
             AND ((cbl_scope = 'address' AND lower(cbl_value) = lower(?)) OR (cbl_scope = 'domain' AND cbl_value = lower(?)))",
            [$email, substr($email, $at + 1)]
        );
        $found = ['address' => null, 'domain' => null];
        foreach ($rows as $row) {
            $found[(string) $row['cbl_scope'] === 'domain' ? 'domain' : 'address'] = self::hydrate($row);
        }
        return $found;
    }

    public function isBlocked(string $email): bool
    {
        $covering = $this->covering($email);
        return $covering['address'] !== null || $covering['domain'] !== null;
    }

    /** @return list<Block> blocks and lifts still to be reported */
    public function unreported(int $limit = 50): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . " FROM cattomail_blocks WHERE cbl_status IN ('pending', 'lift_pending') ORDER BY cbl_id LIMIT " . max(1, $limit)
        ));
    }

    /** @return list<Block> blocks or lifts not yet reported, and refused ones (newest first) */
    public function needingAttention(int $limit = 20): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . " FROM cattomail_blocks WHERE cbl_status IN ('pending', 'lift_pending', 'rejected') ORDER BY cbl_id DESC LIMIT " . max(1, $limit)
        ));
    }

    public function markActive(int $id, string $remoteId): void
    {
        $this->db->executeStatement(
            "UPDATE cattomail_blocks SET cbl_remote_id = ?, cbl_status = CASE WHEN cbl_status = 'pending' THEN 'active' ELSE cbl_status END,
                cbl_confirmed_at = COALESCE(cbl_confirmed_at, ?), cbl_error = NULL WHERE cbl_id = ?",
            [strtolower($remoteId), $this->now(), $id]
        );
    }

    public function requestLift(int $id, ?int $adminId): void
    {
        $this->db->executeStatement(
            "UPDATE cattomail_blocks SET cbl_status = 'lift_pending', cbl_lift_requested_at = ?, cbl_lift_requested_by_s_id = ? WHERE cbl_id = ? AND cbl_status IN ('pending', 'active')",
            [$this->now(), $adminId, $id]
        );
    }

    public function markLifted(int $id): void
    {
        $this->db->executeStatement("UPDATE cattomail_blocks SET cbl_status = 'lifted', cbl_lifted_at = ?, cbl_error = NULL WHERE cbl_id = ?", [$this->now(), $id]);
    }

    /** A refused block (never accepted by catto-mail) is to be reported again. */
    public function reopenRejected(int $id): void
    {
        $this->db->executeStatement("UPDATE cattomail_blocks SET cbl_status = 'pending', cbl_error = NULL WHERE cbl_id = ? AND cbl_status = 'rejected' AND cbl_remote_id IS NULL", [$id]);
    }

    public function markRejected(int $id, string $error): void
    {
        $this->db->executeStatement("UPDATE cattomail_blocks SET cbl_status = 'rejected', cbl_error = ? WHERE cbl_id = ?", [mb_substr($error, 0, 2000), $id]);
    }

    public function keepActive(int $id, string $error): void
    {
        $this->db->executeStatement("UPDATE cattomail_blocks SET cbl_status = 'active', cbl_lift_requested_at = NULL, cbl_lift_requested_by_s_id = NULL, cbl_error = ? WHERE cbl_id = ?",
            [mb_substr($error, 0, 2000), $id]);
    }

    public function noteAttempt(int $id, string $error): void
    {
        $this->db->executeStatement('UPDATE cattomail_blocks SET cbl_attempts = cbl_attempts + 1, cbl_error = ? WHERE cbl_id = ?', [mb_substr($error, 0, 2000), $id]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     * @return Block
     */
    private static function hydrate(array $row): array
    {
        return [
            'cbl_id' => (int) $row['cbl_id'], 'cbl_uuid' => (string) $row['cbl_uuid'], 'cbl_scope' => (string) $row['cbl_scope'] === 'domain' ? 'domain' : 'address',
            'cbl_value' => (string) $row['cbl_value'], 'cbl_idempotency_key' => (string) $row['cbl_idempotency_key'],
            'cbl_remote_id' => $row['cbl_remote_id'] === null ? null : (string) $row['cbl_remote_id'], 'cbl_status' => (string) $row['cbl_status'],
            'cbl_requested_at' => (string) $row['cbl_requested_at'], 'cbl_attempts' => (int) $row['cbl_attempts'],
            'cbl_error' => $row['cbl_error'] === null ? null : (string) $row['cbl_error'],
        ];
    }
}
