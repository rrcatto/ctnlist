<?php

declare(strict_types=1);

namespace App\Queue;

/** Result of a queueing request: how many were queued, or why nothing was. */
final class QueueOutcome
{
    private function __construct(
        public readonly int $queued,
        public readonly ?string $problem,
    ) {
    }

    public static function queued(int $count): self
    {
        return new self($count, null);
    }

    public static function refused(string $problem): self
    {
        return new self(0, $problem);
    }
}
