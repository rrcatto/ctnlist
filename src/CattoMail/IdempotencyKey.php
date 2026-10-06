<?php

declare(strict_types=1);

namespace App\CattoMail;

/**
 * A new Idempotency-Key (a random UUID). Keys are generated once per logical
 * operation, stored with the row that represents it before the request is
 * sent, and reused unchanged by every retry.
 */
final class IdempotencyKey
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
