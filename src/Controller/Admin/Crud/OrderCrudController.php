<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Market\Entity\Order;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin view of the orders: a list and a detail page. Their state moves
 * through the shop (cart, checkout, payment), so nothing here edits them.
 */
class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-receipt';
    }

    /**
     * Orders are born at checkout and move through it: the admin reads them.
     * Shipping, refunds and the like belong in dedicated actions, not in a
     * form over the order's columns.
     */
    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
    }

    /**
     * A disabled action only loses its button in base-bundle-admin; the
     * route itself still answers. Close both here.
     */
    public function new(Request $request): Response
    {
        throw $this->createNotFoundException('Orders are created at checkout.');
    }

    public function edit(Request $request, string $entityId): Response
    {
        throw $this->createNotFoundException('Orders are read-only here.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('store')->add('state')->add('customer');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('reference')->setColumns(4)->setDisabled();
        yield AssociationField::new('store')->setColumns(4)->setDisabled();
        yield AssociationField::new('customer')->setColumns(4)->setDisabled();
        yield TextField::new('state')->setColumns(3)->hideOnForm();
        yield AssociationField::new('paymentMethod')->setColumns(3)->setDisabled();
        yield DateTimeField::new('createdAt')->setColumns(3)->setDisabled();
        yield DateTimeField::new('paidAt')->setColumns(3)->setDisabled();
    }
}
