<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** A role key: lowercase letters, digits, dot, underscore or hyphen, at most 64 characters (roles.r_key). */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class RoleKey extends Compound
{
    public const PATTERN = '/^[a-z0-9._-]{1,64}$/';

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [new Assert\Regex(pattern: self::PATTERN, message: 'Use up to 64 lowercase letters, numbers, dots, underscores or hyphens.')];
    }
}
