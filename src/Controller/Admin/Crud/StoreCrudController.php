<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\BooleanField;
use Base\Field\CurrencyField;
use Base\Field\IdField;
use Base\Field\SlugField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Market\Entity\Store;

/** Admin CRUD for the stores: a name, the currency it trades in, open or shut. */
class StoreCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Store::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-store';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('open');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title')->setColumns(6);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        yield CurrencyField::new('currency')->setColumns(3);
        yield BooleanField::new('open')->setColumns(3);
        yield TextareaField::new('excerpt')->hideOnIndex();
    }
}
