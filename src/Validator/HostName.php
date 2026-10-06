<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** A host name or IP address (SMTP servers). Empty passes. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class HostName extends Compound
{
    public const PATTERN = '/^([A-Za-z0-9.-]{1,253}|\[?[0-9A-Fa-f:.]+\]?)$/';

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Regex(pattern: self::PATTERN, message: 'Enter a valid host name or IP address.')];
    }
}
