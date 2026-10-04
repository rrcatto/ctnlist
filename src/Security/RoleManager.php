<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\RoleRepository;
use App\Repository\SubscriberRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Role administration: custom roles, their ACL permissions and role
 * assignment. System roles (administrator, subscriber) have permissions fixed
 * by the schema. Validation failures throw \InvalidArgumentException with a
 * message for the administrator.
 */
final class RoleManager
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly SubscriberRepository $subscribers,
    ) {
    }

    public function create(string $key, string $name, string $description): void
    {
        $key = strtolower(trim($key));
        $name = trim($name);
        if (!preg_match('/^[a-z0-9._-]{1,64}$/', $key) || $name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Invalid role details.');
        }
        try {
            $this->roles->create($key, $name, trim($description));
        } catch (UniqueConstraintViolationException) {
            throw new \InvalidArgumentException('A role with that key or name already exists.');
        }
    }

    /**
     * @param list<mixed> $permissionIds
     * @throws RoleNotFound
     */
    public function setPermissions(int $roleId, array $permissionIds): void
    {
        $system = $this->roles->isSystem($roleId) ?? throw new RoleNotFound($roleId);
        if ($system) {
            throw new \InvalidArgumentException('System-role permissions are fixed by the schema.');
        }
        $this->roles->replacePermissions($roleId, array_values(array_unique(array_filter(
            array_map('intval', $permissionIds),
            static fn(int $id): bool => $id > 0
        ))));
    }

    public function assign(int $subscriberId, string $roleKey, ?int $assignedBy): void
    {
        if (!$this->subscribers->exists($subscriberId) || !$this->roles->assign($subscriberId, $roleKey, $assignedBy)) {
            throw new \InvalidArgumentException('Unknown subscriber or role.');
        }
    }
}
