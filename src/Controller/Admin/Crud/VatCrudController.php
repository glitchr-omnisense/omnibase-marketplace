<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Market\Entity\Sales\Tax\Vat;

/**
 * The VAT rates, the taxes Pricing charges on each line (Service/Pricing.php
 * reads Vat rows only): a rate and the scopes it applies to - the buyer's
 * region, a product, a taxon. A rate created under "Taxes" is a plain Tax,
 * which Pricing never reads: VAT is created here.
 */
class VatCrudController extends TaxRateCrudController
{
    public static function getEntityFqcn(): string
    {
        return Vat::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-receipt';
    }
}
