<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** A full http(s) web address. Empty passes. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class WebAddress extends Compound
{
    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Url(message: 'Enter a full web address (https://…).', protocols: ['http', 'https'], requireTld: false),
            new Assert\Length(max: 2000),
        ];
    }
}
