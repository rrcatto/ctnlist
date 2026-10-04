<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Role and ACL permission lookups for authentication (roles, subscriber_roles, role_permissions). */
final class AclService
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire(env: 'APP_ADMIN_EMAIL')] private readonly string $initialAdministratorEmail,
    ) {
    }

    /** @return list<string> role keys, e.g. ['administrator', 'subscriber'] */
    public function roleKeys(int $subscriberId): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT r.r_key
             FROM subscriber_roles sr
             JOIN roles r ON r.r_id = sr.sr_r_id
             WHERE sr.sr_s_id = ?
             ORDER BY r.r_key',
            [$subscriberId]
        ));
    }

    /** @return list<string> permission keys, e.g. ['lists.manage'] */
    public function permissions(int $subscriberId): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT ap.ap_key
             FROM subscriber_roles sr
             JOIN role_permissions rp ON rp.rp_r_id = sr.sr_r_id
             JOIN acl_permissions ap ON ap.ap_id = rp.rp_ap_id
             WHERE sr.sr_s_id = ?
             ORDER BY ap.ap_key',
            [$subscriberId]
        ));
    }

    /**
     * Make the subscriber whose email is APP_ADMIN_EMAIL an administrator,
     * but only while no administrator exists yet.
     */
    public function bootstrapInitialAdministrator(int $subscriberId, string $email): bool
    {
        $configured = strtolower(trim($this->initialAdministratorEmail));
        if ($configured === '' || strtolower(trim($email)) !== $configured) {
            return false;
        }

        return $this->db->transactional(function (Connection $db) use ($subscriberId): bool {
            $db->executeStatement("SELECT pg_advisory_xact_lock(hashtext('ctnlist.initial_administrator'))");
            $administrators = (int) $db->fetchOne(
                "SELECT COUNT(*)
                 FROM subscriber_roles sr
                 JOIN roles r ON r.r_id = sr.sr_r_id
                 WHERE r.r_key = 'administrator'"
            );
            if ($administrators > 0) {
                return false;
            }
            $db->executeStatement(
                "INSERT INTO subscriber_roles (sr_s_id, sr_r_id, sr_assigned_by_s_id)
                 SELECT :sid, r_id, :sid FROM roles WHERE r_key = 'administrator'
                 ON CONFLICT (sr_s_id, sr_r_id) DO NOTHING",
                ['sid' => $subscriberId]
            );
            return true;
        });
    }
}
