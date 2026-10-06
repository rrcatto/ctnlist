<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** A TCP port number. Empty passes. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class Port extends Compound
{
    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Range(min: 1, max: 65535, notInRangeMessage: 'The port must be between {{ min }} and {{ max }}.')];
    }
}
