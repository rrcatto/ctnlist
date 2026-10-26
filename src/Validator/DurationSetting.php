<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A period written as App\Util\Duration reads it (1y1w1d1h1m1s, 120d, 1m30s), at least $shortest when given.
 * Empty passes; add NotBlank to require input.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class DurationSetting extends Constraint
{
    public string $tooShortMessage = 'Enter at least {{ shortest }} ({{ text }}).';

    public function __construct(public readonly ?string $shortest = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
