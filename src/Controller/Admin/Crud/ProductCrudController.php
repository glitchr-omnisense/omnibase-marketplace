<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\SelectField;
use Base\Field\CurrencyField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SlugField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Feature;
use Base\Marketplace\Entity\Sales\Channel;

/**
 * Admin CRUD for the products. Prices are integers in the currency's
 * smallest unit (cents, or one token); an empty stock means unlimited.
 */
class ProductCrudController extends AbstractMarketplaceCrudController
{
    /** VAT and prices: the shops' owners set them too (MARKETPLACE_PRICING). */
    protected function isPricing(): bool
    {
        return true;
    }

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
        // A starting price shown as "from" (what is priced on request); empty: the lowest of the variants.
        yield IntegerField::new('priceFrom', '@marketplace.product.price_from_field')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield CurrencyField::new('currency')->setColumns(3);
        yield IntegerField::new('stock')->setColumns(3);
        yield SelectField::new('availability')->setColumns(3)->hideOnIndex();
        yield SelectField::new('channels')->setClass(Channel::class)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        // features are the thread's tags (Product::$features aliases the column)
        yield SelectField::new('tags', 'Features')->setClass(Feature::class)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        yield TextField::new('headline')->hideOnIndex();
        yield TextareaField::new('excerpt')->hideOnIndex();
        yield TextareaField::new('content')->hideOnIndex();
    }
}
