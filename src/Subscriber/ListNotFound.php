<?php

declare(strict_types=1);

namespace App\Subscriber;

final class ListNotFound extends \RuntimeException
{
    public function __construct(int $listId)
    {
        parent::__construct(sprintf('List %d does not exist.', $listId));
    }
}
