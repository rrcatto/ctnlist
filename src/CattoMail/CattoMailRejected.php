<?php

declare(strict_types=1);

namespace App\CattoMail;

/**
 * catto-mail refused the request (400, 401, 403, 404, 409 state conflict,
 * 413, 422): retrying the same request will not help. Carries the RFC 9457
 * problem details.
 */
final class CattoMailRejected extends CattoMailException
{
    /** @param list<array{pointer: string, message: string}> $errors */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $problemType = '',
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }
}
