<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AttributeField;
use Base\Field\IdField;
use Base\Field\SelectField;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Attribute\Adapter\BarcodeAdapter;
use Base\Marketplace\Entity\Product\Identifier;

/**
 * A product's codes (EAN, UPC...): what the marketplaces and feeds match it
 * by. One identifier per product or variant, one barcode per standard.
 */
class ProductIdentifierCrudController extends AbstractMarketplaceCrudController
{
    public static function getEntityFqcn(): string
    {
        return Identifier::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-barcode';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('product');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield SelectField::new('product')->setClass(Product::class)->setColumns(6);
        yield AttributeField::new('barcodes')->setFilter(BarcodeAdapter::class)->allowMultiValues();
    }
}
