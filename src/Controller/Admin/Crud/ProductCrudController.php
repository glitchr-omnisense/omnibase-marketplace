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
use Base\Marketplace\Entity\Store;

/**
 * Admin CRUD for the products. Prices are integers in the currency's
 * smallest unit (cents, or one pepette); an empty stock means unlimited.
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
        yield TextField::new('title', '@marketplace.admin.field.title')->setColumns(6);
        yield SlugField::new('slug', '@marketplace.admin.field.slug')->setTargetFieldName('title')->setColumns(6)->hideOnIndex();
        // The store it is sold in, chosen from the list: AssociationField would embed the store's own
        // fields in the product's form, down to properties nothing can read (Thread::$ownerPositions).
        // Its stores are given as its choices: they are printed as the <select>'s options, so the store is
        // chosen without a script too (left to itself the field asks them of an autocomplete, by script only).
        yield SelectField::new('parent', '@marketplace.admin.field.store')->setClass(Store::class)->setChoices($this->stores())->setColumns(6);
        yield IntegerField::new('unitPrice', '@marketplace.admin.field.unit_price')->setColumns(3);
        // A starting price shown as "from" (what is priced on request); empty: the lowest of the variants.
        yield IntegerField::new('priceFrom', '@marketplace.product.price_from_field')->setColumns(3)->setRequired(false)->hideOnIndex();
        yield CurrencyField::new('currency', '@marketplace.admin.field.currency')->setColumns(3);
        yield IntegerField::new('stock', '@marketplace.admin.field.stock')->setRequired(false)->setColumns(3);
        yield SelectField::new('availability', '@marketplace.admin.field.availability')->setColumns(3)->hideOnIndex();
        yield SelectField::new('channels', '@marketplace.admin.field.channels')->setClass(Channel::class)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        // features are the thread's tags (Product::$features aliases the column)
        yield SelectField::new('tags', '@marketplace.admin.field.features')->setClass(Feature::class)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        yield TextField::new('headline', '@marketplace.admin.field.headline')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('excerpt', '@marketplace.admin.field.excerpt')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('content', '@marketplace.admin.field.content')->setRequired(false)->hideOnIndex();
    }

    /** @return array<string, Store> title => store, in the titles' order */
    private function stores(): array
    {
        $stores = [];
        foreach ($this->entityManager->getRepository(Store::class)->findAll() as $store) {
            $title = (string) ($store->getTitle() ?? $store->getSlug());
            // Two stores of one name: told apart by their address.
            $stores[isset($stores[$title]) ? $title.' ('.$store->getSlug().')' : $title] = $store;
        }
        ksort($stores, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $stores;
    }
}
