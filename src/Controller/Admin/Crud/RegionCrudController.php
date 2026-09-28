<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\BooleanField;
use Base\Field\CountryField;
use Base\Field\CurrencyField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\SlugField;
use Base\Field\TranslationField;
use Base\Market\Entity\Sales\Region;
use Base\Market\Entity\Store;

/**
 * The regions a store sells to: their countries, currency and priority (the
 * first region covering a visitor's country wins). A disabled region is
 * kept but no longer offered. A region orders were placed in stays.
 */
class RegionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Region::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-earth-europe';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('enabled');
    }

    public function isDeletable(object $entity): bool
    {
        return 0 === \count($entity->getOrders());
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TranslationField::new()->showOnIndex('label')->setFields(['label' => ['required' => true]])->setColumns(6);
        yield SlugField::new('slug')->setColumns(3)->hideOnIndex();
        yield BooleanField::new('enabled')->withConfirmation()->setColumns(3);
        // Region::$stores aliases the tag's threads
        yield SelectField::new('threads', 'Stores')->setClass(Store::class)->allowMultipleChoices()->setColumns(6);
        yield CountryField::new('countries')->setColumns(6)->setRequired(false);
        yield CurrencyField::new('currency')->setColumns(3);
        yield IntegerField::new('priority')->setColumns(3);
    }
}
