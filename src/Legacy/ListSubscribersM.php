<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;

class ListSubscribersM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'list_subscribers');
    }

    public function readMembership(int $subscriberId, int $listId): bool
    {
        $this->load([
            'ls_s_id = :sid AND ls_l_id = :lid',
            ':sid' => $subscriberId,
            ':lid' => $listId,
        ]);
        return $this->valid();
    }
}