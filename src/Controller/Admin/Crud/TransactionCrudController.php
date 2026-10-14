<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Marketplace\Entity\Order\Transaction;

/**
 * The payments recorded against orders, as the gateways reported them. A
 * record of what happened: read, never written or deleted from here.
 */
class TransactionCrudController extends AbstractMarketplaceCrudController
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
        yield TextField::new('number', '@marketplace.admin.field.number')->setRequired(false)->setColumns(4);
        yield AssociationField::new('order', '@marketplace.admin.field.order')->setRequired(false)->setColumns(4);
        yield TextField::new('state', '@marketplace.admin.field.state')->setColumns(4);
        yield IntegerField::new('totalAmount', '@marketplace.admin.field.total_amount')->setColumns(4);
        yield TextField::new('currencyCode', '@marketplace.admin.field.currency_code')->setRequired(false)->setColumns(4);
        yield TextField::new('clientEmail', '@marketplace.admin.field.client_email')->setRequired(false)->hideOnIndex();
        yield TextField::new('description', '@marketplace.admin.field.description')->setRequired(false)->hideOnIndex();
        yield TextField::new('comment', '@marketplace.admin.field.comment')->setRequired(false)->hideOnIndex();
        yield DateTimeField::new('createdAt', '@marketplace.admin.field.created_at');
        yield DateTimeField::new('updatedAt', '@marketplace.admin.field.updated_at')->hideOnIndex();
    }
}
