<?php

namespace Base\Marketplace\Validator;

use Base\Marketplace\Service\CompanyRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class CompanyNumberValidator extends ConstraintValidator
{
    public function __construct(private readonly CompanyRegistry $registry)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (null === $value || '' === trim((string) $value)) {
            return;
        }
        \assert($constraint instanceof CompanyNumber);

        $status = $this->registry->lookup((string) $value)['status'];
        if (CompanyRegistry::INVALID === $status) {
            $this->context->buildViolation($constraint->invalid)->setTranslationDomain('marketplace')->addViolation();
        } elseif (CompanyRegistry::NOT_FOUND === $status) {
            $this->context->buildViolation($constraint->unknown)->setTranslationDomain('marketplace')->addViolation();
        }
    }
}
