<?php

declare(strict_types=1);

class SubscriberRolesM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'subscriber_roles');
    }
}