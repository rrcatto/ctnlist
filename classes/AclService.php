<?php

declare(strict_types=1);

final class AclService
{
    public function __construct(private Base $fat, private \DB\SQL $db)
    {
    }

    public function can(int $subscriberId, string $permission): bool
    {
        if ($subscriberId < 1 || $permission === '') {
            return false;
        }

        $rows = $this->db->exec(
            'SELECT 1
             FROM subscriber_roles sr
             JOIN role_permissions rp ON rp.rp_r_id = sr.sr_r_id
             JOIN acl_permissions ap ON ap.ap_id = rp.rp_ap_id
             WHERE sr.sr_s_id = :sid AND ap.ap_key = :permission
             LIMIT 1',
            [':sid' => $subscriberId, ':permission' => $permission]
        );

        return $rows !== [];
    }

    public function hasRole(int $subscriberId, string $roleKey): bool
    {
        $rows = $this->db->exec(
            'SELECT 1
             FROM subscriber_roles sr
             JOIN roles r ON r.r_id = sr.sr_r_id
             WHERE sr.sr_s_id = :sid AND r.r_key = :role
             LIMIT 1',
            [':sid' => $subscriberId, ':role' => $roleKey]
        );
        return $rows !== [];
    }

    public function isAdministrator(int $subscriberId): bool
    {
        return $this->hasRole($subscriberId, 'administrator');
    }

    public function assignRole(int $subscriberId, string $roleKey, ?int $assignedBy = null): void
    {
        $this->db->exec(
            'INSERT INTO subscriber_roles (sr_s_id, sr_r_id, sr_assigned_by_s_id)
             SELECT :sid, r_id, :assigned_by FROM roles WHERE r_key = :role
             ON CONFLICT (sr_s_id, sr_r_id) DO NOTHING',
            [':sid' => $subscriberId, ':role' => $roleKey, ':assigned_by' => $assignedBy]
        );
    }

    public function removeRole(int $subscriberId, string $roleKey): bool
    {
        if ($roleKey === 'subscriber') {
            return false;
        }

        if ($roleKey === 'administrator') {
            $rows = $this->db->exec(
                "SELECT COUNT(*) AS total
                 FROM subscriber_roles sr
                 JOIN roles r ON r.r_id = sr.sr_r_id
                 WHERE r.r_key = 'administrator'"
            );
            if ((int) ($rows[0]['total'] ?? 0) <= 1 && $this->hasRole($subscriberId, 'administrator')) {
                return false;
            }
        }

        $this->db->exec(
            'DELETE FROM subscriber_roles
             WHERE sr_s_id = :sid
               AND sr_r_id = (SELECT r_id FROM roles WHERE r_key = :role)',
            [':sid' => $subscriberId, ':role' => $roleKey]
        );
        return true;
    }

    public function bootstrapInitialAdministrator(int $subscriberId, string $email): bool
    {
        $configured = strtolower(trim($this->env('APP_ADMIN_EMAIL')));
        if ($configured === '' || strtolower(trim($email)) !== $configured) {
            return false;
        }

        $this->db->begin();
        try {
            $this->db->exec("SELECT pg_advisory_xact_lock(hashtext('ctnlist.initial_administrator'))");
            $rows = $this->db->exec(
                "SELECT COUNT(*) AS total
                 FROM subscriber_roles sr
                 JOIN roles r ON r.r_id = sr.sr_r_id
                 WHERE r.r_key = 'administrator'"
            );
            if ((int) ($rows[0]['total'] ?? 0) === 0) {
                $this->assignRole($subscriberId, 'administrator', $subscriberId);
                $this->db->commit();
                return true;
            }
            $this->db->commit();
            return false;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** @return list<string> */
    public function permissions(int $subscriberId): array
    {
        $rows = $this->db->exec(
            'SELECT DISTINCT ap.ap_key
             FROM subscriber_roles sr
             JOIN role_permissions rp ON rp.rp_r_id = sr.sr_r_id
             JOIN acl_permissions ap ON ap.ap_id = rp.rp_ap_id
             WHERE sr.sr_s_id = :sid
             ORDER BY ap.ap_key',
            [':sid' => $subscriberId]
        );
        return array_values(array_map(static fn(array $row): string => (string) $row['ap_key'], $rows));
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($value) ? trim($value) : '';
    }
}