<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Marketplace\Invoice\Transmission\EReporting;
use Base\Marketplace\Invoice\Transmission\Entity\EReport;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The shop's e-reporting periods (Invoice\Transmission\EReporting): what was
 * collected of the sales to private persons and abroad, and of their
 * payments, period by period, their deadline, and where their transmission
 * stands. Read, never edited: collected again while not sent, sent once.
 *
 * Registered only with omnisense/omnibill (config/services.php): a site
 * links it in its menu when it turns the e-reporting on,
 * MenuItem::linkToCrud(EReport::class, ...).
 */
class EReportCrudController extends AbstractMarketplaceCrudController
{
    private EReporting $reporting;
    private TranslatorInterface $translator;

    #[Required]
    public function setReportingServices(EReporting $reporting, TranslatorInterface $translator): void
    {
        $this->reporting = $reporting;
        $this->translator = $translator;
    }

    public static function getEntityFqcn(): string
    {
        return EReport::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-file-export';
    }

    public function isDeletable(object $entity): bool
    {
        return false;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['startsOn' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $collect = Action::new('collect', '@marketplace.ereport.admin.collect', 'fa-solid fa-arrows-rotate')
            ->linkToCrudAction('collect')
            ->displayIf(fn (EReport $report) => !$report->isSent());
        $send = Action::new('send', '@marketplace.ereport.admin.send', 'fa-solid fa-paper-plane')
            ->linkToCrudAction('send')
            ->displayIf(fn (EReport $report) => !$report->isSent());
        $actions = parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL] as $page) {
            $actions->add($page, $collect)->add($page, $send);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('kind', '@marketplace.ereport.admin.kind')->setColumns(2);
        yield DateTimeField::new('startsOn', '@marketplace.ereport.admin.starts_on')->setColumns(2);
        yield DateTimeField::new('endsOn', '@marketplace.ereport.admin.ends_on')->setColumns(2);
        yield DateTimeField::new('dueOn', '@marketplace.ereport.admin.due_on')->setColumns(2);
        yield TextField::new('state', '@marketplace.admin.field.state')->setColumns(2)
            ->formatValue(fn (?string $state, EReport $report) => $state.(null !== $report->getError() ? ' — '.$report->getError() : ''));
        yield TextField::new('reference', '@marketplace.ereport.admin.reference')->setRequired(false)->hideOnIndex();
        yield TextField::new('data', '@marketplace.ereport.admin.data')->hideOnIndex()->hideOnForm()
            ->formatValue(fn ($value, EReport $report) => json_encode($report->getData(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    #[AdminAction('/{entityId}/collect')]
    public function collect(string $entityId): Response
    {
        /** @var EReport $report */
        $report = $this->findEntity($entityId);
        $this->reporting->collect($report->getKind(), $report->getStartsOn());
        $this->addFlash('success', $this->translator->trans('@marketplace.ereport.admin.collected', ['{period}' => (string) $report]));

        return $this->redirectToIndex();
    }

    #[AdminAction('/{entityId}/send')]
    public function send(string $entityId): Response
    {
        /** @var EReport $report */
        $report = $this->findEntity($entityId);
        $this->reporting->send($report);
        $this->addFlash($report->isSent() ? 'success' : 'warning', $report->isSent()
            ? $this->translator->trans('@marketplace.ereport.admin.sent', ['{period}' => (string) $report, '{reference}' => (string) $report->getReference()])
            : $this->translator->trans('@marketplace.ereport.admin.not_sent', ['{period}' => (string) $report, '{error}' => (string) $report->getError()]));

        return $this->redirectToIndex();
    }
}
