<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\Request\AmountInput;
use App\ValueObject\Money;
use BcMath\Number;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PositiveAmountValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PositiveAmount) {
            throw new UnexpectedTypeException($constraint, PositiveAmount::class);
        }

        if (null === $value) {
            return;
        }

        $amount = AmountInput::toDecimalString($value);

        if (null === $amount || !Money::isValidFormat($amount) || new Number($amount)->compare(0) <= 0) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
