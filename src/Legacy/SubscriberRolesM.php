<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;

class SubscriberRolesM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'subscriber_roles');
    }
}