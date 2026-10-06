<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Repository\RoleRepository;
use App\Repository\SubscriberRepository;
use App\Security\AclService;
use App\Security\RoleManager;
use App\Security\SubscriberUser;
use App\Tests\Integration\IntegrationTestCase;

/** Custom-role administration, system-role protection and escalation rules. */
final class RoleManagerTest extends IntegrationTestCase
{
    public function testCustomRoleLifecycle(): void
    {
        $manager = $this->service(RoleManager::class);
        $roles = $this->service(RoleRepository::class);
        $admin = $this->actor(administrator: true);

        $manager->create('editors', 'Editors', 'Edit things');
        $role = $roles->findByKey('editors');
        self::assertNotNull($role);
        $id = $role['r_id'];

        $manager->update($id, ' Content editors ', 'Edits content');
        self::assertSame(['editors', 'Content editors', 'Edits content'], [$roles->find($id)['r_key'] ?? '', $roles->find($id)['r_name'] ?? '', $roles->find($id)['r_description'] ?? ''], 'the key never changes');

        $manager->setPermissions($id, $this->permissionIds(['lists.manage', 'logs.view']), $admin);
        self::assertSame(['lists.manage', 'logs.view'], $roles->permissionKeys($id));

        $jane = $this->createSubscriber('jane@example.com');
        $joe = $this->createSubscriber('joe@example.com');
        self::assertTrue($manager->assign($jane, 'editors', $admin));
        self::assertFalse($manager->assign($jane, 'editors', $admin), 'already held');
        self::assertTrue($manager->assign($joe, 'editors', $admin));
        self::assertSame(2, $roles->memberCount($id));
        self::assertSame(['jane@example.com', 'joe@example.com'], array_column($roles->members($id, 0, 10), 's_email'));
        self::assertSame(['editors', 'subscriber'], $this->service(AclService::class)->roleKeys($jane));

        $manager->unassign($jane, $id);
        self::assertSame(['subscriber'], $this->service(AclService::class)->roleKeys($jane), 'only that role removed');
        try {
            $manager->unassign($jane, $id);
            self::fail('removed twice');
        } catch (\InvalidArgumentException) {
        }

        $manager->delete($id);
        self::assertNull($roles->find($id));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscriber_roles WHERE sr_r_id = ?', [$id]), 'assignments removed');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM role_permissions WHERE rp_r_id = ?', [$id]), 'grants removed');
        self::assertSame(['subscriber'], $this->service(AclService::class)->roleKeys($joe), 'members keep their other roles');
    }

    public function testSystemRolesAreProtected(): void
    {
        $manager = $this->service(RoleManager::class);
        $roles = $this->service(RoleRepository::class);
        $jane = $this->createSubscriber('jane@example.com');
        $before = $this->db->fetchAllAssociative('SELECT r_key, r_name, r_system FROM roles WHERE r_system ORDER BY r_key');

        foreach (['administrator', 'subscriber'] as $key) {
            $id = ($roles->findByKey($key) ?? [])['r_id'] ?? 0;
            foreach ([
                fn() => $manager->update($id, 'Renamed', ''),
                fn() => $manager->delete($id),
                fn() => $manager->unassign($jane, $id),
                fn() => $manager->setPermissions($id, [], $this->actor(administrator: true)),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail("changed system role {$key}");
                } catch (\InvalidArgumentException) {
                }
            }
        }
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT r_key, r_name, r_system FROM roles WHERE r_system ORDER BY r_key'));
        self::assertSame(['subscriber'], $this->service(AclService::class)->roleKeys($jane));
    }

    /** Non-administrators cannot hand out permissions they do not hold. */
    public function testNoPrivilegeEscalation(): void
    {
        $manager = $this->service(RoleManager::class);
        $roles = $this->service(RoleRepository::class);
        $staff = $this->actor(administrator: false, permissions: ['roles.manage', 'acl.manage', 'logs.view']);
        $manager->create('helpers', 'Helpers', '');
        $id = ($roles->findByKey('helpers') ?? [])['r_id'] ?? 0;
        $jane = $this->createSubscriber('jane@example.com');

        $manager->setPermissions($id, $this->permissionIds(['logs.view']), $staff);
        try {
            $manager->setPermissions($id, $this->permissionIds(['logs.view', 'subscribers.manage']), $staff);
            self::fail('granted a permission the actor lacks');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(['logs.view'], $roles->permissionKeys($id));

        self::assertTrue($manager->assign($jane, 'helpers', $staff), 'holds every permission of the role');
        try {
            $manager->assign($jane, 'administrator', $staff);
            self::fail('a non-administrator made someone an administrator');
        } catch (\InvalidArgumentException) {
        }

        $manager->setPermissions($id, $this->permissionIds(['subscribers.manage']), $this->actor(administrator: true));
        $manager->setPermissions($id, [], $staff);
        self::assertSame([], $roles->permissionKeys($id), 'removing grants is always allowed');
    }

    /** Self-assignment, roles changed after the fact, and acl.manage are covered by the same rule. */
    public function testNoEscalationThroughSelfAssignmentOrLaterGrants(): void
    {
        $manager = $this->service(RoleManager::class);
        $roles = $this->service(RoleRepository::class);
        $staff = $this->actor(administrator: false, permissions: ['roles.manage', 'logs.view']);
        $manager->create('readers', 'Readers', '');
        $readers = ($roles->findByKey('readers') ?? [])['r_id'] ?? 0;
        $manager->setPermissions($readers, $this->permissionIds(['logs.view']), $this->actor(administrator: true));

        self::assertTrue($manager->assign($staff->id, 'readers', $staff), 'a role whose permissions they hold, even for themselves');
        foreach (['administrator', 'readers-plus'] as $key) {
            if ($key === 'readers-plus') {
                // An administrator later raises another role above what the staff member holds.
                $manager->create('readers-plus', 'Readers plus', '');
                $manager->setPermissions(($roles->findByKey('readers-plus') ?? [])['r_id'] ?? 0, $this->permissionIds(['logs.view', 'settings.manage']), $this->actor(administrator: true));
            }
            try {
                $manager->assign($staff->id, $key, $staff);
                self::fail("assigned {$key} to themselves");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('do not hold', $e->getMessage());
            }
            self::assertFalse($roles->holds($staff->id, ($roles->findByKey($key) ?? [])['r_id'] ?? 0), "{$key} not held");
        }

        try {
            $manager->setPermissions($readers, $this->permissionIds(['logs.view']), $staff);
            self::fail('changed permissions without acl.manage');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('acl.manage', $e->getMessage());
        }
        $manager->setPermissions($readers, $this->permissionIds(['logs.view', 'settings.manage']), $this->actor(administrator: true));
        self::assertSame(['logs.view', 'settings.manage'], $roles->permissionKeys($readers), 'administrators are unrestricted');
    }

    /** The assignment picker searches; it does not stop at the first 500 subscribers. */
    public function testSubscriberSearchFindsAnySubscriber(): void
    {
        $this->db->executeStatement(
            "INSERT INTO subscribers (s_email) SELECT 'bulk' || lpad(n::text, 4, '0') || '@example.com' FROM generate_series(1, 600) n"
        );
        $last = $this->createSubscriber('zz-last@example.com', 'Zelda', 'Zulu');
        $search = $this->service(SubscriberRepository::class);

        self::assertSame([$last], array_column($search->search('zz-last', 20), 's_id'), 'by email');
        self::assertSame([$last], array_column($search->search('zelda', 20), 's_id'), 'by first name');
        self::assertSame([$last], array_column($search->search('ZULU', 20), 's_id'), 'by last name');
        self::assertSame([$last], array_column($search->search($this->subscriberUuid($last), 20), 's_id'), 'by UUID');
        self::assertCount(20, $search->search('bulk', 20), 'bounded');
        self::assertSame([], $search->search('100%', 20), 'LIKE wildcards are literal');
        self::assertTrue($this->service(RoleManager::class)->assign($last, 'subscriber', $this->actor(administrator: true)) === false, 'already held by everyone');
    }

    /** @param list<string> $permissions */
    private function actor(bool $administrator, array $permissions = []): SubscriberUser
    {
        $id = (int) ($this->db->fetchOne("SELECT s_id FROM subscribers WHERE s_email = 'actor@example.com'") ?: $this->createSubscriber('actor@example.com'));
        return new SubscriberUser($id, $this->subscriberUuid($id), 'actor@example.com', '', '', $administrator ? ['administrator'] : [], $permissions);
    }

    /**
     * @param list<string> $keys
     * @return list<int>
     */
    private function permissionIds(array $keys): array
    {
        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT ap_id FROM acl_permissions WHERE ap_key IN (?) ORDER BY ap_key',
            [$keys],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        ));
    }
}
