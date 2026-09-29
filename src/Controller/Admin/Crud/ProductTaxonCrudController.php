<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Controller\Backoffice\Crud\Thread\TaxonCrudController;
use Base\Field\SelectField;
use Base\Market\Entity\Product\Taxon;
use Base\Market\Entity\Store;
use Base\Market\Controller\Admin\MarketAdminTrait;

/** The product categories of a store: base-bundle's taxonomy, with the store they belong to. */
class ProductTaxonCrudController extends TaxonCrudController
{
    use MarketAdminTrait;

    public static function getEntityFqcn(): string
    {
        return Taxon::class;
    }

    protected function taxonFields(string $pageName): iterable
    {
        yield SelectField::new('store')->setClass(Store::class)->setRequired(false)->setColumns(6);
    }
}
