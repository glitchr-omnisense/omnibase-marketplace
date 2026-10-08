<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Filter\Filters;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Service\Invoices;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The shop's invoices and credit notes: read, never edited - an issued
 * invoice does not change. Its actions: its PDF, sending it to its buyer,
 * marking it paid, cancelling it by a credit note. An order's invoice is
 * issued from the order (OrderCrudController's "Issue the invoice"), or by
 * itself when the order is paid (marketplace.invoice.auto_issue).
 */
class InvoiceCrudController extends AbstractMarketplaceCrudController
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
        return Invoice::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-file-invoice';
    }

    public function isDeletable(object $entity): bool
    {
        return false;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $pdf = Action::new('pdf', '@marketplace.invoice.admin.download', 'fa-solid fa-file-pdf')
            ->linkToUrl(fn (Invoice $invoice) => $this->generateUrl('marketplace_invoice', ['number' => $invoice->getNumber()]));
        $send = Action::new('send', '@marketplace.invoice.admin.send', 'fa-solid fa-paper-plane')
            ->linkToCrudAction('send')
            ->displayIf(fn (Invoice $invoice) => null !== ($invoice->getBuyer()['email'] ?? null));
        $paid = Action::new('markPaid', '@marketplace.invoice.admin.paid', 'fa-solid fa-check')
            ->linkToCrudAction('markPaid')
            ->displayIf(fn (Invoice $invoice) => !$invoice->isCreditNote() && !$invoice->isPaid() && !$invoice->isCancelled());
        $credit = Action::new('credit', '@marketplace.invoice.admin.credit', 'fa-solid fa-rotate-left')
            ->linkToCrudAction('credit')
            ->askConfirmation('@marketplace.invoice.admin.credit_confirm')
            ->displayIf(fn (Invoice $invoice) => !$invoice->isCreditNote() && !$invoice->isCancelled());

        $actions = parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL] as $page) {
            $actions->add($page, $pdf)->add($page, $send)->add($page, $paid)->add($page, $credit);
        }

        return $actions;
    }

    public function new(Request $request): Response
    {
        throw $this->createNotFoundException('An invoice is issued for an order.');
    }

    public function edit(Request $request, string $entityId): Response
    {
        throw $this->createNotFoundException('An issued invoice does not change: correct it with a credit note.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('type')->add('state')->add('order');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('number')->setColumns(3);
        yield TextField::new('type')->setColumns(2);
        yield TextField::new('state')->setColumns(2);
        yield TextField::new('orderReference')->setColumns(3);
        yield DateTimeField::new('issuedAt')->setColumns(2);
        yield DateTimeField::new('sentAt')->hideOnIndex();
        yield DateTimeField::new('paidAt')->hideOnIndex();
        yield DateTimeField::new('cancelledAt')->hideOnIndex();
    }

    #[AdminAction('/{entityId}/send')]
    public function send(string $entityId): Response
    {
        /** @var Invoice $invoice */
        $invoice = $this->findEntity($entityId);

        return $this->attempt(function () use ($invoice) {
            $this->invoices->send($invoice);

            return $this->translator->trans('@marketplace.invoice.admin.sent', ['{number}' => $invoice->getNumber(), '{email}' => $invoice->getBuyer()['email'] ?? '']);
        });
    }

    #[AdminAction('/{entityId}/paid')]
    public function markPaid(string $entityId): Response
    {
        /** @var Invoice $invoice */
        $invoice = $this->findEntity($entityId);

        return $this->attempt(function () use ($invoice) {
            $this->invoices->markPaid($invoice);

            return $this->translator->trans('@marketplace.invoice.admin.marked_paid', ['{number}' => $invoice->getNumber()]);
        });
    }

    #[AdminAction('/{entityId}/credit')]
    public function credit(string $entityId): Response
    {
        /** @var Invoice $invoice */
        $invoice = $this->findEntity($entityId);

        return $this->attempt(function () use ($invoice) {
            $creditNote = $this->invoices->credit($invoice);

            return $this->translator->trans('@marketplace.invoice.admin.credited', ['{number}' => $creditNote->getNumber(), '{invoice}' => $invoice->getNumber()]);
        });
    }

    /** @param callable(): string $do the action, answering what to tell */
    private function attempt(callable $do): Response
    {
        try {
            $this->addFlash('success', $do());
        } catch (\LogicException $e) {
            $this->addFlash('danger', $this->translator->trans('@marketplace.invoice.admin.refused', ['{message}' => $e->getMessage()]));
        }

        return $this->redirectToIndex();
    }
}
