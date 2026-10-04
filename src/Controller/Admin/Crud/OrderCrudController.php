<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Marketplace\Entity\Order;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Base\Admin\Filter\Filter;
use Base\Marketplace\Enum\OrderState;
use Doctrine\ORM\QueryBuilder;

/**
 * Admin view of the orders: a list and a detail page. Their state moves
 * through the shop (cart, checkout, payment), so nothing here edits them.
 */
class OrderCrudController extends AbstractMarketplaceCrudController
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

    public function configureRecordNote(object $entity): ?string
    {
        return $entity->getUpdatedAt() ? 'Last change: '.$entity->getUpdatedAt()->format('Y-m-d H:i').' UTC' : null;
    }

    public function configureFilters(Filters $filters): Filters
    {
        // Carts and orders share the table: "cart" splits them (every
        // ORDER_CART* state is a cart, abandoned ones included).
        return $filters
            ->add(Filter::new('cart', 'Cart')->asBoolean()->applyWith(function (QueryBuilder $queryBuilder, string $alias, mixed $value): void {
                $isCart = '1' === $value || 'true' === $value || true === $value;
                $queryBuilder->andWhere(sprintf('%s.state %s LIKE :cart_state', $alias, $isCart ? '' : 'NOT'))
                    ->setParameter('cart_state', OrderState::CART.'%');
            }))
            ->add('store')->add('state')->add('customer');
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
        yield AssociationField::new('region')->setColumns(3)->hideOnIndex();
        yield AssociationField::new('shippingMethod')->setColumns(3)->hideOnIndex();
        yield AssociationField::new('transactions')->renderAsCount()->hideOnIndex();
        yield AssociationField::new('shipments')->renderAsCount()->hideOnIndex();
        // The buyers' files for its lines (the artwork of a printed item): downloads.
        yield TextField::new('attachments', '@marketplace.attachment.line')->hideOnIndex()->hideOnForm()
            ->setTemplatePath('@Marketplace/admin/field/attachments.html.twig');
    }
}
