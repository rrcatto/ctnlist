<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/** An email address the application can store (subscribers.s_email) and send to. Empty passes; add NotBlank to require one. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class EmailAddress extends Compound
{
    public const MESSAGE = 'Enter a valid email address.';

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Email(message: self::MESSAGE),
            new Assert\Length(max: 254, maxMessage: 'An email address has at most {{ limit }} characters.'),
        ];
    }
}
