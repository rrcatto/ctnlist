<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** A list shortcode: 3 to 6 uppercase letters or digits (lists.l_shortcode). */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class ListShortcode extends Compound
{
    public const PATTERN = '/^[A-Z0-9]{3,6}$/';

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Regex(pattern: self::PATTERN, message: 'Use 3 to 6 uppercase letters or numbers.')];
    }
}
