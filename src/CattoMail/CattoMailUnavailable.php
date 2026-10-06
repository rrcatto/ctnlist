<?php

declare(strict_types=1);

namespace App\CattoMail;

/**
 * A retryable failure: timeout, connection failure, 5xx, 408, 429, or 409 for
 * a request still in progress. Retry later with the same Idempotency-Key;
 * the operation may or may not have taken effect.
 */
final class CattoMailUnavailable extends CattoMailException
{
    /** Seconds from a Retry-After header, if catto-mail sent one. */
    public ?int $retryAfter = null;
}
