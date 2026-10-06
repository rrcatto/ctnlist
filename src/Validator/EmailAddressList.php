<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Text holding email addresses (separated by commas, spaces or new lines)
 * must contain at least one usable address (EmailNormaliser rules), and at
 * most $max different ones. Empty passes; add NotBlank to require input.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class EmailAddressList extends Constraint
{
    public string $noneMessage = 'No usable email addresses were found.';
    public string $tooManyMessage = 'Enter no more than {{ max }} different addresses.';

    public function __construct(public readonly ?int $max = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
