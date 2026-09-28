<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Controller\Backoffice\Crud\Layout\Attribute\AdapterCrudController;
use Base\Field\SelectField;
use Base\Market\Entity\Product\Attribute\Adapter\BarcodeAdapter;

/**
 * The barcode standards products can be identified with (EAN-13, UPC...):
 * one per standard, used by the product identifiers and the feeds.
 */
class BarcodeStandardCrudController extends AdapterCrudController
{
    public static function getEntityFqcn(): string
    {
        return BarcodeAdapter::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-barcode';
    }

    protected function adapterFields(string $pageName): iterable
    {
        yield SelectField::new('standard')->setColumns(4);
    }
}
