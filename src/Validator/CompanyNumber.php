<?php

namespace Base\Market\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A SIREN or SIRET that exists: well formed, and known to the State's
 * register (Service\CompanyRegistry). A register that does not answer lets
 * it through - the request is then marked unchecked, not refused.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CompanyNumber extends Constraint
{
    public string $invalid = 'company.invalid';
    public string $unknown = 'company.unknown';
}
