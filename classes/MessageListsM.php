<?php

declare(strict_types=1);

class MessageListsM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'message_lists');
    }
}