<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\SelectField;
use Base\Field\CurrencyField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SlugField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Market\Entity\Product;

/**
 * Admin CRUD for the products. Prices are integers in the currency's
 * smallest unit (cents, or one token); an empty stock means unlimited.
 */
class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-tag';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('parent');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title')->setColumns(6);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        // The store it is sold in, chosen from the list: AssociationField would embed the store's own
        // fields in the product's form, down to properties nothing can read (Thread::$ownerPositions).
        yield SelectField::new('parent', 'Store')->setColumns(6);
        yield IntegerField::new('unitPrice')->setColumns(3);
        yield CurrencyField::new('currency')->setColumns(3);
        yield IntegerField::new('stock')->setColumns(3);
        yield TextField::new('headline')->hideOnIndex();
        yield TextareaField::new('excerpt')->hideOnIndex();
        yield TextareaField::new('content')->hideOnIndex();
    }
}
