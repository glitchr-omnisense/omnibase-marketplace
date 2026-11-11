<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Service\Invoices;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Base\Admin\Filter\Filter;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Parser;
use Base\Marketplace\Enum\OrderState;
use Doctrine\ORM\QueryBuilder;

/**
 * Admin view of the orders: a list and a detail page. Their state moves
 * through the shop (cart, checkout, payment), so nothing here edits them.
 */
class OrderCrudController extends AbstractMarketplaceCrudController
{
    private Invoices $invoices;
    private TranslatorInterface $translator;

    #[Required]
    public function setInvoiceServices(Invoices $invoices, TranslatorInterface $translator): void
    {
        $this->invoices = $invoices;
        $this->translator = $translator;
    }

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
        // An order paid (or waiting for its transfer) has its invoice issued here, once.
        $issue = Action::new('issueInvoice', '@marketplace.invoice.admin.issue', 'fa-solid fa-file-invoice')
            ->linkToCrudAction('issueInvoice')
            ->displayIf(fn (Order $order) => $order->isPaid() && null === $this->invoices->of($order));

        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT)
            ->add(Actions::PAGE_DETAIL, $issue)->add(Actions::PAGE_INDEX, $issue);
    }

    #[AdminAction('/{entityId}/invoice')]
    public function issueInvoice(string $entityId): Response
    {
        /** @var Order $order */
        $order = $this->findEntity($entityId);
        try {
            $invoice = $this->invoices->issue($order);
            $this->addFlash('success', $this->translator->trans('@marketplace.invoice.admin.issued', ['{number}' => $invoice->getNumber()]));
        } catch (\LogicException $e) {
            $this->addFlash('danger', $this->translator->trans('@marketplace.invoice.admin.refused', ['{message}' => $e->getMessage()]));
        }

        return $this->redirectToIndex();
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
        // ORDER_CART* state is a cart, abandoned ones included). Said in the
        // core's finder language, which every persistence reads (matchWith());
        // the same on Doctrine's QueryBuilder where the list is still built on
        // it, under omnisense/omnibase 4.x (applyWith()).
        $isCart = static fn (mixed $value): bool => '1' === $value || 'true' === $value || true === $value;

        return $filters
            ->add(Filter::new('cart', 'Cart')->asBoolean()
                ->matchWith(static fn (mixed $value) => $isCart($value)
                    ? Criterion::of('state', OrderState::CART.'%', Parser::OPTION_PARTIAL)
                    : Criterion::of('state', OrderState::CART.'%', Parser::OPTION_NOT_EQUAL, Parser::OPTION_PARTIAL))
                ->applyWith(static function (QueryBuilder $queryBuilder, string $alias, mixed $value) use ($isCart): void {
                    $queryBuilder->andWhere(sprintf('%s.state %s LIKE :cart_state', $alias, $isCart($value) ? '' : 'NOT'))
                        ->setParameter('cart_state', OrderState::CART.'%');
                }))
            ->add('store')->add('state')->add('customer');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('reference', '@marketplace.admin.field.reference')->setColumns(4)->setDisabled();
        yield AssociationField::new('store', '@marketplace.admin.field.store')->setColumns(4)->setDisabled();
        yield AssociationField::new('customer', '@marketplace.admin.field.customer')->setColumns(4)->setDisabled();
        yield TextField::new('state', '@marketplace.admin.field.state')->setColumns(3)->hideOnForm();
        yield AssociationField::new('paymentMethod', '@marketplace.admin.field.payment_method')->setColumns(3)->setDisabled();
        yield DateTimeField::new('createdAt', '@marketplace.admin.field.created_at')->setColumns(3)->setDisabled();
        yield DateTimeField::new('paidAt', '@marketplace.admin.field.paid_at')->setColumns(3)->setDisabled();
        yield AssociationField::new('region', '@marketplace.admin.field.region')->setColumns(3)->hideOnIndex();
        yield AssociationField::new('shippingMethod', '@marketplace.admin.field.shipping_method')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield AssociationField::new('transactions', '@marketplace.admin.field.transactions')->renderAsCount()->hideOnIndex();
        yield AssociationField::new('shipments', '@marketplace.admin.field.shipments')->renderAsCount()->hideOnIndex();
        // The buyers' files for its lines (the artwork of a printed item): downloads.
        yield TextField::new('attachments', '@marketplace.attachment.line')->hideOnIndex()->hideOnForm()
            ->setTemplatePath('@Marketplace/admin/field/attachments.html.twig');
        // Its invoices, each where it stands - on its gateway too (omnisense/omnibill): "F-2026-000041 · sent · Déposée (afnor)".
        yield TextField::new('invoices', '@marketplace.invoice.admin.invoices')->hideOnIndex()->hideOnForm()
            ->formatValue(fn ($value, Order $order) => implode("\n", array_map(fn ($invoice) => implode(' · ', array_filter([$invoice->getNumber(), $invoice->getState(), $this->invoices->lifecycle($invoice), $this->invoices->guidance($invoice)])), $this->invoices->repository()->ofOrder($order))) ?: '—');
    }
}
