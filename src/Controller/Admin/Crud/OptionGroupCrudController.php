<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Field\BooleanField;
use Base\Field\CollectionField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\OptionGroup;
use Base\Marketplace\Form\ProductOptionType;

/**
 * The options of the products: a group per choice the buyer makes (a
 * cooking, extras, a finish) - one option or several, how many at least and
 * at most - and its options, each with what it adds to the price.
 */
class OptionGroupCrudController extends AbstractMarketplaceCrudController
{
    /** Prices: the shops' owners set their options too (MARKETPLACE_PRICING). */
    protected function isPricing(): bool
    {
        return true;
    }

    public static function getEntityFqcn(): string
    {
        return OptionGroup::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-list-check';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield SelectField::new('product', '@marketplace.options.admin.product')->setClass(Product::class)->setColumns(6);
        yield TextField::new('label', '@marketplace.options.admin.group')->setColumns(6);
        yield BooleanField::new('multiple', '@marketplace.options.admin.multiple')->setColumns(3);
        yield IntegerField::new('minimum', '@marketplace.options.admin.minimum')->setColumns(3);
        yield IntegerField::new('maximum', '@marketplace.options.admin.maximum')->setColumns(3)->setRequired(false);
        yield IntegerField::new('position', '@marketplace.options.admin.position')->setColumns(3)->hideOnIndex();
        yield CollectionField::new('options', '@marketplace.options.admin.options')->setEntryType(ProductOptionType::class)
            ->allowAdd()->allowDelete()->hideOnIndex()->setFormTypeOptions(['by_reference' => false, 'allow_object' => true]);
    }
}
