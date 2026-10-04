<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Roles, ACL permissions and their assignments (`roles`, `acl_permissions`,
 * `role_permissions`, `subscriber_roles`).
 *
 * @phpstan-type Role array{r_id: int, r_key: string, r_name: string, r_description: string, r_system: bool}
 * @phpstan-type Permission array{ap_id: int, ap_key: string, ap_name: string}
 */
final class RoleRepository
{
    public function __construct(private readonly Connection $db)
    {
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
}
