<?php

declare(strict_types=1);

class AclPermissionsM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'acl_permissions');
    }
}