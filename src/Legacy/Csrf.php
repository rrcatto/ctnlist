<?php

declare(strict_types=1);

namespace App\Legacy;

use App\Security\Csrf as CsrfTokenId;
use Base;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Legacy CSRF helper, backed by Symfony's session-stored token manager
 * (LegacyFramework puts it in the hive), so legacy and Symfony forms share
 * one token.
 */
final class Csrf
{
    public const MANAGER = 'CSRF_TOKEN_MANAGER';

    public static function token(Base $fat): string
    {
        return self::manager($fat)->getToken(CsrfTokenId::TOKEN_ID)->getValue();
    }

    public static function field(Base $fat): string
    {
        return '<input type="hidden" name="' . CsrfTokenId::FIELD . '" value="'
            . htmlspecialchars(self::token($fat), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
    }

    public static function validate(Base $fat): bool
    {
        $actual = trim((string) $fat->get('POST.' . CsrfTokenId::FIELD));
        return $actual !== '' && self::manager($fat)->isTokenValid(new CsrfToken(CsrfTokenId::TOKEN_ID, $actual));
    }

    public static function requireValid(Base $fat): void
    {
        if (!self::validate($fat)) {
            $fat->error(403, 'Invalid request token.');
        }
    }

    private static function manager(Base $fat): CsrfTokenManagerInterface
    {
        return $fat->get(self::MANAGER);
    }
}
