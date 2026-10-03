<?php

declare(strict_types=1);

final class Csrf
{
    public static function token(Base $fat): string
    {
        $token = (string) $fat->get('SESSION.csrf');
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $fat->set('SESSION.csrf', $token);
        }
        return $token;
    }

    public static function field(Base $fat): string
    {
        return '<input type="hidden" name="csrf" value="'
            . htmlspecialchars(self::token($fat), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
    }

    public static function validate(Base $fat): bool
    {
        $expected = (string) $fat->get('SESSION.csrf');
        $actual = trim((string) $fat->get('POST.csrf'));
        return $expected !== '' && $actual !== '' && hash_equals($expected, $actual);
    }

    public static function requireValid(Base $fat): void
    {
        if (!self::validate($fat)) {
            $fat->error(403, 'Invalid request token.');
        }
    }
}