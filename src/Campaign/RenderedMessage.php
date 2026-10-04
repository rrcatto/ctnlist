<?php

declare(strict_types=1);

namespace App\Campaign;

final class RenderedMessage
{
    public function __construct(
        public readonly string $html,
        public readonly string $text,
    ) {
    }
}
