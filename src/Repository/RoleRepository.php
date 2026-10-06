<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Roles, ACL permissions and their assignments (`roles`, `acl_permissions`,
 * `role_permissions`, `subscriber_roles`).
 *
 * @phpstan-type Role array{r_id: int, r_key: string, r_name: string, r_description: string, r_system: bool}
 * @phpstan-type Permission array{ap_id: int, ap_key: string, ap_name: string}
 * @phpstan-type RoleMember array{s_id: int, s_uuid: string, s_email: string, s_fname: string, s_lname: string, sr_assigned_at: string}
 */
final class RoleRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<Role> system roles first, then by name */
    public function all(): array
    {
        return array_map(static fn(array $row): array => [
            'r_id' => (int) $row['r_id'],
            'r_key' => (string) $row['r_key'],
            'r_name' => (string) $row['r_name'],
            'r_description' => (string) $row['r_description'],
            'r_system' => (bool) $row['r_system'],
        ], $this->db->fetchAllAssociative(
            'SELECT r_id, r_key, r_name, r_description, r_system FROM roles ORDER BY r_system DESC, r_name'
        ));
    }

    /** @return Role|null */
    public function find(int $roleId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT r_id, r_key, r_name, r_description, r_system FROM roles WHERE r_id = ?', [$roleId]);
        return $row === false ? null : [
            'r_id' => (int) $row['r_id'],
            'r_key' => (string) $row['r_key'],
            'r_name' => (string) $row['r_name'],
            'r_description' => (string) $row['r_description'],
            'r_system' => (bool) $row['r_system'],
        ];
    }

    /** @return Role|null */
    public function findByKey(string $roleKey): ?array
    {
        $id = $this->db->fetchOne('SELECT r_id FROM roles WHERE r_key = ?', [$roleKey]);
        return $id === false ? null : $this->find((int) $id);
    }

    /** @return array<int, int> number of subscribers holding each role id */
    public function memberCounts(): array
    {
        $counts = [];
        foreach ($this->db->fetchAllAssociative('SELECT sr_r_id, COUNT(*) AS n FROM subscriber_roles GROUP BY sr_r_id') as $row) {
            $counts[(int) $row['sr_r_id']] = (int) $row['n'];
        }
        return $counts;
    }

    public function memberCount(int $roleId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscriber_roles WHERE sr_r_id = ?', [$roleId]);
    }

    /** @return list<RoleMember> subscribers holding the role, by email */
    public function members(int $roleId, int $offset, int $limit): array
    {
        return array_map(static fn(array $row): array => [
            's_id' => (int) $row['s_id'],
            's_uuid' => (string) $row['s_uuid'],
            's_email' => (string) $row['s_email'],
            's_fname' => (string) $row['s_fname'],
            's_lname' => (string) $row['s_lname'],
            'sr_assigned_at' => (string) $row['sr_assigned_at'],
        ], $this->db->fetchAllAssociative(
            'SELECT s.s_id, s.s_uuid, s.s_email, s.s_fname, s.s_lname, sr.sr_assigned_at
             FROM subscriber_roles sr JOIN subscribers s ON s.s_id = sr.sr_s_id
             WHERE sr.sr_r_id = ?
             ORDER BY s.s_email
             LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$roleId]
        ));
    }

    /**
     * @param list<int> $subscriberIds
     * @return array<int, list<Role>> roles held by each subscriber id (system roles first)
     */
    public function rolesForSubscribers(array $subscriberIds): array
    {
        if ($subscriberIds === []) {
            return [];
        }
        $held = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT sr.sr_s_id, r.r_id, r.r_key, r.r_name, r.r_description, r.r_system
             FROM subscriber_roles sr JOIN roles r ON r.r_id = sr.sr_r_id
             WHERE sr.sr_s_id IN (?)
             ORDER BY r.r_system DESC, r.r_name',
            [$subscriberIds],
            [ArrayParameterType::INTEGER]
        );
        foreach ($rows as $row) {
            $held[(int) $row['sr_s_id']][] = [
                'r_id' => (int) $row['r_id'],
                'r_key' => (string) $row['r_key'],
                'r_name' => (string) $row['r_name'],
                'r_description' => (string) $row['r_description'],
                'r_system' => (bool) $row['r_system'],
            ];
        }
        return $held;
    }

    /** @return list<string> permission keys granted to the role */
    public function permissionKeys(int $roleId): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT ap.ap_key FROM role_permissions rp JOIN acl_permissions ap ON ap.ap_id = rp.rp_ap_id WHERE rp.rp_r_id = ? ORDER BY ap.ap_key',
            [$roleId]
        ));
    }

    /**
     * @param list<int> $permissionIds
     * @return list<string> the keys of these permission ids
     */
    public function permissionKeysForIds(array $permissionIds): array
    {
        return $permissionIds === [] ? [] : array_map('strval', $this->db->fetchFirstColumn(
            'SELECT ap_key FROM acl_permissions WHERE ap_id IN (?)',
            [$permissionIds],
            [ArrayParameterType::INTEGER]
        ));
    }

    /** Whether the role is a system role; null when it does not exist. */
    public function isSystem(int $roleId): ?bool
    {
        // fetchOne() cannot tell a missing row from a FALSE column value.
        $row = $this->db->fetchAssociative('SELECT r_system FROM roles WHERE r_id = ?', [$roleId]);
        return $row === false ? null : (bool) $row['r_system'];
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return array_map(static fn(array $row): array => [
            'ap_id' => (int) $row['ap_id'],
            'ap_key' => (string) $row['ap_key'],
            'ap_name' => (string) $row['ap_name'],
        ], $this->db->fetchAllAssociative('SELECT ap_id, ap_key, ap_name FROM acl_permissions ORDER BY ap_key'));
    }

    /** @return array<int, list<int>> permission ids granted to each role id */
    public function grantedPermissionIds(): array
    {
        $granted = [];
        foreach ($this->db->fetchAllAssociative('SELECT rp_r_id, rp_ap_id FROM role_permissions') as $row) {
            $granted[(int) $row['rp_r_id']][] = (int) $row['rp_ap_id'];
        }
        return $granted;
    }

    public function create(string $key, string $name, string $description): void
    {
        $this->db->insert('roles', ['r_key' => $key, 'r_name' => $name, 'r_description' => $description]);
    }

    /** @param list<int> $permissionIds */
    public function replacePermissions(int $roleId, array $permissionIds): void
    {
        $this->db->transactional(function (Connection $db) use ($roleId, $permissionIds): void {
            $db->executeStatement('DELETE FROM role_permissions WHERE rp_r_id = ?', [$roleId]);
            foreach ($permissionIds as $permissionId) {
                $db->executeStatement(
                    'INSERT INTO role_permissions (rp_r_id, rp_ap_id)
                     SELECT ?, ap_id FROM acl_permissions WHERE ap_id = ?
                     ON CONFLICT DO NOTHING',
                    [$roleId, $permissionId]
                );
            }
        });
    }

    /** Rename a custom role or change its description (the key never changes). */
    public function update(int $roleId, string $name, string $description): void
    {
        $this->db->executeStatement(
            'UPDATE roles SET r_name = ?, r_description = ?, r_updated_at = ? WHERE r_id = ? AND r_system = FALSE',
            [$name, $description, $this->clock->now()->format('Y-m-d H:i:s'), $roleId]
        );
    }

    /**
     * Delete a custom role with its assignments; its permission grants go with
     * it (role_permissions cascades). Returns false for a system or unknown role.
     */
    public function deleteCustom(int $roleId): bool
    {
        return $this->db->transactional(function (Connection $db) use ($roleId): bool {
            if ($db->fetchAssociative('SELECT 1 FROM roles WHERE r_id = ? AND r_system = FALSE FOR UPDATE', [$roleId]) === false) {
                return false;
            }
            // subscriber_roles.sr_r_id is ON DELETE RESTRICT: remove the assignments explicitly.
            $db->executeStatement('DELETE FROM subscriber_roles WHERE sr_r_id = ?', [$roleId]);
            return $db->executeStatement('DELETE FROM roles WHERE r_id = ? AND r_system = FALSE', [$roleId]) === 1;
        });
    }

    /** Remove one role from one subscriber; returns whether an assignment was removed. */
    public function unassign(int $subscriberId, int $roleId): bool
    {
        return $this->db->executeStatement('DELETE FROM subscriber_roles WHERE sr_s_id = ? AND sr_r_id = ?', [$subscriberId, $roleId]) === 1;
    }

    /** Give a subscriber a role; returns false when the role key does not exist. */
    public function assign(int $subscriberId, string $roleKey, ?int $assignedBy): bool
    {
        $roleId = $this->db->fetchOne('SELECT r_id FROM roles WHERE r_key = ?', [$roleKey]);
        if ($roleId === false) {
            return false;
        }
        $this->db->executeStatement(
            'INSERT INTO subscriber_roles (sr_s_id, sr_r_id, sr_assigned_by_s_id) VALUES (?, ?, ?)
             ON CONFLICT (sr_s_id, sr_r_id) DO NOTHING',
            [$subscriberId, (int) $roleId, $assignedBy]
        );
        return true;
    }

    public function holds(int $subscriberId, int $roleId): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM subscriber_roles WHERE sr_s_id = ? AND sr_r_id = ?', [$subscriberId, $roleId]) !== false;
    }
}
