<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\InvalidField;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;

/** Shows a service's rejection of valid-looking input on the form. */
final class FormErrors
{
    /**
     * Attach $e at its field (InvalidField naming a field of $form), else to
     * the form as a whole.
     */
    public static function attach(FormInterface $form, \InvalidArgumentException $e): void
    {
        $target = $e instanceof InvalidField && $form->has($e->field) ? $form->get($e->field) : $form;
        $target->addError(new FormError($e->getMessage()));
    }
}
