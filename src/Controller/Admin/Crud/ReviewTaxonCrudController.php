<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Controller\Backoffice\Crud\Thread\TaxonCrudController;
use Base\Field\SelectField;
use Base\Marketplace\Entity\Review\Taxon;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Controller\Admin\MarketplaceAdminTrait;

/** The review categories of a store: base-bundle's taxonomy, with the store they belong to. */
class ReviewTaxonCrudController extends TaxonCrudController
{
    use MarketplaceAdminTrait;

    public static function getEntityFqcn(): string
    {
        return Taxon::class;
    }

    protected function taxonFields(string $pageName): iterable
    {
        yield SelectField::new('store')->setClass(Store::class)->setRequired(false)->setColumns(6);
    }
}
