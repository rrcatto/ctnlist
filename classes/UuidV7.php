<?php

declare(strict_types=1);

/**
 * RFC 9562 UUID version 7 generator.
 *
 * The first 48 bits contain the Unix epoch timestamp in milliseconds. The
 * remaining non-version/variant bits are generated with random_bytes().
 */
final class UuidV7
{
    public static function generate(?DateTimeInterface $time = null): string
    {
        $milliseconds = $time === null
            ? (int) floor(microtime(true) * 1000)
            : ((int) $time->format('U') * 1000) + intdiv((int) $time->format('u'), 1000);

        if ($milliseconds < 0 || $milliseconds > 0xFFFFFFFFFFFF) {
            throw new InvalidArgumentException('UUIDv7 timestamp is outside the 48-bit range.');
        }

        $timestampHex = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);
        $timestampBytes = hex2bin($timestampHex);
        if ($timestampBytes === false) {
            throw new RuntimeException('Unable to encode the UUIDv7 timestamp.');
        }

        $bytes = $timestampBytes . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function isValid(string $uuid): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            trim($uuid)
        ) === 1;
    }

    public static function timestampMilliseconds(string $uuid): int
    {
        if (!self::isValid($uuid)) {
            throw new InvalidArgumentException('Invalid UUIDv7 value.');
        }

        $hex = str_replace('-', '', strtolower(trim($uuid)));
        return (int) hexdec(substr($hex, 0, 12));
    }
}