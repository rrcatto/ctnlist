<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * LIKE patterns from user input: % and _ (and the escape character) are
 * matched literally, so a search for "a_b" or "100%" finds exactly that.
 * PostgreSQL's LIKE uses backslash as its default escape character.
 */
final class Like
{
    public static function contains(string $term): string
    {
        return '%' . self::escape(trim($term)) . '%';
    }

    public static function startsWith(string $term): string
    {
        return self::escape(trim($term)) . '%';
    }

    private static function escape(string $term): string
    {
        return addcslashes($term, '%_\\');
    }
}
