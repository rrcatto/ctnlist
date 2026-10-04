<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;

class RolePermissionsM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'role_permissions');
    }
}