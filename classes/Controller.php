<?php

declare(strict_types=1);

class Controller
{
    public function beforeroute(): void
    {
    }

    public function afterroute(): void
    {
    }

    public static function allowed(Base $fat, string $permission): bool
    {
        if ((int) $fat->get('uadmin') === 1) {
            return true;
        }
        return in_array($permission, (array) $fat->get('acl_permissions'), true);
    }
}