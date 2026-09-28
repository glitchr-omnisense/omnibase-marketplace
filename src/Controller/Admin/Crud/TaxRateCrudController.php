<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\AttributeField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\TextField;
use Base\Market\Entity\Sales\Tax;

/**
 * The taxes (VAT included, a Tax of its own kind): a rate, and the scopes it
 * applies to - a region, a product, a taxon. Pricing takes the first tax
 * whose scopes hold the item or the buyer's region.
 */
class TaxRateCrudController extends AbstractCrudController
{
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
