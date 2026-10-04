<?php

declare(strict_types=1);

namespace App\Legacy;

/** Thrown from F3's ONERROR hook; LegacyBridge renders the error page. */
final class LegacyHttpError extends \RuntimeException
{
    public function __construct(public readonly int $statusCode, string $text)
    {
        parent::__construct($text, $statusCode);
    }
}
