<?php

declare(strict_types=1);

class RolePermissionsM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'role_permissions');
    }
}