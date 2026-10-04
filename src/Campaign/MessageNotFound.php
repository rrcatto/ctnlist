<?php

declare(strict_types=1);

namespace App\Campaign;

final class MessageNotFound extends \RuntimeException
{
    public function __construct(string $muid)
    {
        parent::__construct(sprintf('Message %s does not exist.', $muid));
    }
}
