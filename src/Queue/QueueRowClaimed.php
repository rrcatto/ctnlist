<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Another queue run removed (and so staged) this queue row first; the
 * staging transaction that was about to stage it again is rolled back.
 */
final class QueueRowClaimed extends \RuntimeException
{
}
