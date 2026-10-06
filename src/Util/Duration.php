<?php

declare(strict_types=1);

namespace App\Util;

/** A number of seconds in words, as shown to people: "30 minutes", "1 hour", "1 hour 30 minutes". */
final class Duration
{
    public static function describe(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
        }
        if ($rest > 0) {
            $parts[] = $rest . ' ' . ($rest === 1 ? 'minute' : 'minutes');
        }
        return implode(' ', $parts);
    }
}
