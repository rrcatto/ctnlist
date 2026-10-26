<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * A whole number from a query or form field: 0 when the field is missing,
 * empty or not a whole number. InputBag::getInt() answers those with a 400
 * page, so an "All lists" choice or a cleared "Rows per page" box broke the
 * search instead of meaning "no filter" and "the default".
 */
final class RequestNumber
{
    /** @param InputBag<string> $bag */
    public static function of(InputBag $bag, string $key): int
    {
        $value = filter_var(trim($bag->getString($key)), FILTER_VALIDATE_INT);
        return is_int($value) ? $value : 0;
    }
}
