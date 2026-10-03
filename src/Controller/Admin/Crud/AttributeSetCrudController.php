<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Field\CollectionField;
use Base\Field\IdField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Marketplace\Entity\Product\AttributeSet;
use Base\Marketplace\Entity\Product\Taxon;
use Base\Marketplace\Form\AttributeSetFieldType;

/**
 * The typed sheets: for a kind of product (a taxon), its attributes in
 * order, which are required, which the list filters on. The attributes
 * themselves (their label in each language, their type, their unit) are
 * omnibase's adapters.
 */
class AttributeSetCrudController extends AbstractMarketplaceCrudController
{
    public static function getEntityFqcn(): string
    {
        return AttributeSet::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-table-list';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', '@marketplace.attribute_set.name')->setColumns(6);
        yield SelectField::new('taxon', '@marketplace.attribute_set.taxon')->setClass(Taxon::class)->setRequired(false)->setColumns(6);
        yield CollectionField::new('fields', '@marketplace.attribute_set.fields')->setEntryType(AttributeSetFieldType::class)
            ->allowAdd()->allowDelete()->hideOnIndex()->setFormTypeOptions(['by_reference' => false, 'allow_object' => true]);
    }
}
