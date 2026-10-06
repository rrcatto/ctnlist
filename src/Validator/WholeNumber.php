<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * A whole number within optional bounds. Number fields reject text with
 * their invalid_message (self::NOT_A_NUMBER). Empty passes.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class WholeNumber extends Compound
{
    public const NOT_A_NUMBER = 'Enter a whole number.';

    public function __construct(public readonly ?int $min = null, public readonly ?int $max = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        if ($this->min === null && $this->max === null) {
            return [new Assert\Type(type: 'integer', message: self::NOT_A_NUMBER)];
        }
        if ($this->min !== null && $this->max !== null) {
            return [new Assert\Range(min: $this->min, max: $this->max, notInRangeMessage: 'Enter a whole number from {{ min }} to {{ max }}.')];
        }
        return [$this->min !== null
            ? new Assert\Range(min: $this->min, minMessage: 'Enter a whole number of at least {{ limit }}.')
            : new Assert\Range(max: $this->max, maxMessage: 'Enter a whole number of at most {{ limit }}.')];
    }
}
