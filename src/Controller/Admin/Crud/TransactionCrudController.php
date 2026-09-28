<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Market\Entity\Order\Transaction;

/**
 * The payments recorded against orders, as the gateways reported them. A
 * record of what happened: read, never written or deleted from here.
 */
class TransactionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transaction::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-money-check-dollar';
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('state')->add('order');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('number')->setColumns(4);
        yield AssociationField::new('order')->setColumns(4);
        yield TextField::new('state')->setColumns(4);
        yield IntegerField::new('totalAmount', 'Amount (cents)')->setColumns(4);
        yield TextField::new('currencyCode', 'Currency')->setColumns(4);
        yield TextField::new('clientEmail')->hideOnIndex();
        yield TextField::new('description')->hideOnIndex();
        yield TextField::new('comment')->hideOnIndex();
        yield DateTimeField::new('createdAt');
        yield DateTimeField::new('updatedAt')->hideOnIndex();
    }
}
