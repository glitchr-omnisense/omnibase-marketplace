<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\AttributeField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\TextField;
use Base\Marketplace\Entity\Sales\Tax;

/**
 * Every tax, VAT rates included: a rate, and the scopes it applies to - a
 * region, a store, a taxon, a product. One created here is an additional tax;
 * VAT rates are created on their own screen (VatCrudController), the ones
 * Pricing adds to each line.
 */
class TaxRateCrudController extends AbstractMarketplaceCrudController
{
    /** VAT and prices: the shops' owners set them too (MARKETPLACE_PRICING). */
    protected function isPricing(): bool
    {
        return true;
    }

    public static function getEntityFqcn(): string
    {
        return Tax::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-percent';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label')->setColumns(6);
        yield NumberField::new('rate')->percentage(0, 100)->setColumns(3);
        yield AttributeField::new('scopes')->setFilter(AbstractScopeAdapter::class);
    }
}
