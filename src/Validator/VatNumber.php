<?php

namespace Base\Marketplace\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * An intra-EU VAT number VIES knows (Service\VatNumbers): well formed - a
 * French one with its key - and valid at the European Commission. VIES that
 * does not answer lets a well-formed number through rather than refuse it.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class VatNumber extends Constraint
{
    public string $invalid = 'vat_number.invalid';
    public string $unknown = 'vat_number.unknown';
}
