<?php

declare(strict_types=1);

namespace App\Validator;

/**
 * A business rule rejected one input field (e.g. a name that is already
 * taken). Services throw it so a form can show the message at that field
 * (App\Form\FormErrors); without a form it is an ordinary
 * \InvalidArgumentException with a message for the user.
 */
final class InvalidField extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
