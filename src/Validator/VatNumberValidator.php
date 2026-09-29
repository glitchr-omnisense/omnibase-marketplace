<?php

namespace Base\Market\Validator;

use Base\Market\Service\VatNumbers;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class VatNumberValidator extends ConstraintValidator
{
    public function __construct(private readonly VatNumbers $numbers)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (null === $value || '' === trim((string) $value)) {
            return;
        }
        \assert($constraint instanceof VatNumber);

        $status = $this->numbers->check((string) $value)['status'];
        if (VatNumbers::NOT_A_NUMBER === $status) {
            $this->context->buildViolation($constraint->invalid)->setTranslationDomain('market')->addViolation();
        } elseif (VatNumbers::UNKNOWN === $status) {
            $this->context->buildViolation($constraint->unknown)->setTranslationDomain('market')->addViolation();
        }
    }
}
