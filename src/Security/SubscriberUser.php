<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An authenticated subscriber with their role keys (e.g. `administrator`) and
 * ACL permission keys (e.g. `lists.manage`). Identified by the subscriber UUID.
 */
final class SubscriberUser implements UserInterface
{
    /**
     * @param list<string> $roleKeys
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly array $roleKeys,
        public readonly array $permissions,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->uuid !== '' ? $this->uuid : throw new \LogicException('A signed-in subscriber always has a UUID.');
    }

    /** @return list<string> ROLE_USER plus ROLE_<KEY> per role, e.g. ROLE_ADMINISTRATOR */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->roleKeys as $key) {
            $roles[] = 'ROLE_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $key));
        }
        return array_values(array_unique($roles));
    }

    public function isAdministrator(): bool
    {
        return in_array('administrator', $this->roleKeys, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
