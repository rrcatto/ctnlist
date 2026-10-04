<?php

declare(strict_types=1);

namespace App\Security;

final class RoleNotFound extends \RuntimeException
{
    public function __construct(int $roleId)
    {
        parent::__construct(sprintf('Role %d does not exist.', $roleId));
    }
}
