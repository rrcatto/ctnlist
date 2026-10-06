<?php

declare(strict_types=1);

namespace App\Validator;

use App\Subscriber\EmailNormaliser;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class EmailAddressListValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EmailAddressList) {
            throw new UnexpectedTypeException($constraint, EmailAddressList::class);
        }
        if ($value === null || trim((string) $value) === '') {
            return;
        }
        $emails = EmailNormaliser::extract((string) $value);
        if ($emails === []) {
            $this->context->buildViolation($constraint->noneMessage)->addViolation();
        } elseif ($constraint->max !== null && count($emails) > $constraint->max) {
            $this->context->buildViolation($constraint->tooManyMessage)->setParameter('{{ max }}', (string) $constraint->max)->addViolation();
        }
    }
}
