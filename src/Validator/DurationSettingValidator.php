<?php

declare(strict_types=1);

namespace App\Validator;

use App\Util\Duration;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class DurationSettingValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DurationSetting) {
            throw new UnexpectedTypeException($constraint, DurationSetting::class);
        }
        if ($value === null || trim((string) $value) === '') {
            return;
        }
        try {
            $period = Duration::parse((string) $value);
        } catch (\InvalidArgumentException $e) {
            $this->context->buildViolation($e->getMessage())->addViolation();
            return;
        }
        if ($constraint->shortest !== null && $period->seconds() < ($shortest = Duration::parse($constraint->shortest))->seconds()) {
            $this->context->buildViolation($constraint->tooShortMessage)
                ->setParameter('{{ shortest }}', $shortest->words())->setParameter('{{ text }}', $shortest->text)->addViolation();
        }
    }
}
