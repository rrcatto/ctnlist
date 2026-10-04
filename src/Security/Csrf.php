<?php

declare(strict_types=1);

namespace App\Security;

/** The CSRF token id shared by all state-changing forms (field name `csrf`). */
final class Csrf
{
    public const TOKEN_ID = 'ctnlist';
    public const FIELD = 'csrf';
}
