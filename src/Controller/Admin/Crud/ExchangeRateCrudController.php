<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\CurrencyField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\TextField;
use Base\Market\Entity\Sales\Forex;

/**
 * The exchange rates (Forex) prices and promotions are converted with: one
 * row per currency pair, refreshed by the rate providers or set by hand.
 */
class ExchangeRateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Forex::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-money-bill-transfer';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('source')->add('target');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield CurrencyField::new('source')->setColumns(3);
        yield CurrencyField::new('target')->setColumns(3);
        yield NumberField::new('rate')->setNumDecimals(10)->setColumns(3);
        yield TextField::new('provider')->setRequired(false)->setColumns(3);
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
