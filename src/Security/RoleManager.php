<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\RoleRepository;
use App\Repository\SubscriberRepository;
use App\Validator\InvalidField;
use App\Validator\RoleKey;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Role administration: custom roles (create, rename, describe, delete),
 * their ACL permissions, and role assignment.
 *
 * - System roles (administrator, subscriber) are fixed: their permissions,
 *   name and key cannot change, they cannot be deleted, and their
 *   assignments are not removed here (every subscriber holds `subscriber`,
 *   added by the insert trigger; administrators are never demoted by role
 *   administration, so the site cannot be locked out).
 * - A role key is fixed once created: it is the stable identifier used by
 *   code and assignments; the name and description are what people see.
 * - No privilege escalation: a non-administrator may only assign a role, or
 *   grant a permission to a role, if they hold every permission involved.
 *   Administrators hold all permissions.
 *
 * Validation failures throw \InvalidArgumentException with a message for the
 * administrator; unknown roles throw RoleNotFound.
 */
final class RoleManager
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly SubscriberRepository $subscribers,
    ) {
    }

    /** @throws InvalidField for a malformed or taken key or name */
    public function create(string $key, string $name, string $description): void
    {
        $key = strtolower(trim($key));
        if (!preg_match(RoleKey::PATTERN, $key)) {
            throw new InvalidField('key', 'Invalid role key.');
        }
        $name = self::name($name);
        if ($this->roles->findByKey($key) !== null) {
            throw new InvalidField('key', 'A role with that key already exists.');
        }
        $this->assertNameFree($name, null);
        try {
            $this->roles->create($key, $name, trim($description));
        } catch (UniqueConstraintViolationException $e) {
            throw self::duplicate($e);
        }
    }

    /**
     * @throws \InvalidArgumentException for a system role, InvalidField for a malformed or taken name
     * @throws RoleNotFound
     */
    public function update(int $roleId, string $name, string $description): void
    {
        $this->customRole($roleId, 'System roles cannot be renamed.');
        $name = self::name($name);
        $this->assertNameFree($name, $roleId);
        try {
            $this->roles->update($roleId, $name, trim($description));
        } catch (UniqueConstraintViolationException $e) {
            throw self::duplicate($e);
        }
    }

    /**
     * Delete a custom role, its permission grants and its assignments.
     *
     * @throws \InvalidArgumentException for a system role
     * @throws RoleNotFound
     */
    public function delete(int $roleId): void
    {
        $this->customRole($roleId, 'System roles cannot be deleted.');
        if (!$this->roles->deleteCustom($roleId)) {
            throw new RoleNotFound($roleId);
        }
    }

    /**
     * @param list<mixed> $permissionIds
     * @throws RoleNotFound
     */
    public function setPermissions(int $roleId, array $permissionIds, SubscriberUser $actor): void
    {
        $system = $this->roles->isSystem($roleId) ?? throw new RoleNotFound($roleId);
        if ($system) {
            throw new \InvalidArgumentException('System-role permissions are fixed by the schema.');
        }
        // The route requires acl.manage too; the rule also holds for any other caller.
        if (!$actor->isAdministrator() && !$actor->hasPermission('acl.manage')) {
            throw new \InvalidArgumentException('Changing role permissions needs the acl.manage permission.');
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $permissionIds),
            static fn(int $id): bool => $id > 0
        )));
        $added = array_diff($this->roles->permissionKeysForIds($ids), $this->roles->permissionKeys($roleId));
        self::requireHeld($actor, $added, 'You cannot grant permissions you do not hold yourself.');
        $this->roles->replacePermissions($roleId, $ids);
    }

    /** @return bool true when assigned, false when the subscriber already held the role */
    public function assign(int $subscriberId, string $roleKey, SubscriberUser $actor): bool
    {
        $role = $this->roles->findByKey($roleKey);
        if ($role === null || !$this->subscribers->exists($subscriberId)) {
            throw new \InvalidArgumentException('Unknown subscriber or role.');
        }
        self::requireHeld($actor, $this->roles->permissionKeys($role['r_id']), 'You cannot assign a role with permissions you do not hold yourself.');
        if ($this->roles->holds($subscriberId, $role['r_id'])) {
            return false;
        }
        $this->roles->assign($subscriberId, $roleKey, $actor->id);
        return true;
    }

    /**
     * @throws \InvalidArgumentException for a system role or a missing assignment
     * @throws RoleNotFound
     */
    public function unassign(int $subscriberId, int $roleId): void
    {
        $this->customRole($roleId, 'System role assignments cannot be removed here.');
        if (!$this->roles->unassign($subscriberId, $roleId)) {
            throw new \InvalidArgumentException('That subscriber does not hold this role.');
        }
    }

    /** @throws RoleNotFound */
    private function customRole(int $roleId, string $systemMessage): void
    {
        $system = $this->roles->isSystem($roleId) ?? throw new RoleNotFound($roleId);
        if ($system) {
            throw new \InvalidArgumentException($systemMessage);
        }
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidField('name', 'A role name is required (at most 100 characters).');
        }
        return $name;
    }

    private function assertNameFree(string $name, ?int $exceptId): void
    {
        $existing = $this->roles->findByName($name);
        if ($existing !== null && $existing['r_id'] !== $exceptId) {
            throw new InvalidField('name', 'A role with that name already exists.');
        }
    }

    /** A unique-constraint race (another request took the key or name first). */
    private static function duplicate(UniqueConstraintViolationException $e): InvalidField
    {
        return str_contains($e->getMessage(), 'roles_r_key_key')
            ? new InvalidField('key', 'A role with that key already exists.', $e)
            : new InvalidField('name', 'A role with that name already exists.', $e);
    }

    /** @param array<string> $permissions */
    private static function requireHeld(SubscriberUser $actor, array $permissions, string $message): void
    {
        if (!$actor->isAdministrator() && array_diff($permissions, $actor->permissions) !== []) {
            throw new \InvalidArgumentException($message);
        }
    }
}
