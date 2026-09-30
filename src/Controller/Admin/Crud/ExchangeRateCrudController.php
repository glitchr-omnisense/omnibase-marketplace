<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\CurrencyField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\NumberField;
use Base\Field\TextField;
use Base\Marketplace\Entity\Sales\Forex;
use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Marketplace\Security\MarketplaceVoter;
use Base\Marketplace\Service\ExchangeRates;
use Base\Marketplace\Service\ExchangeRatesException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The exchange rates (Forex) prices and promotions are converted with: one
 * row per currency pair, set by hand or refreshed on demand - the refresh is
 * the only way the shop reaches a rate provider (ExchangeRates: one request
 * for every pair, Fixer's counted against its monthly quota), and only the
 * creators may run it.
 */
class ExchangeRateCrudController extends AbstractMarketplaceCrudController
{
    public function __construct(private readonly ExchangeRates $rates)
    {
    }

    public function configureActions(Actions $actions): Actions
    {
        $label = $this->rates->usesFixer()
            ? sprintf('Mettre à jour depuis Fixer (%d/%d ce mois-ci)', $this->rates->requestsThisMonth(), ExchangeRates::FIXER_QUOTA)
            : 'Mettre à jour (Banque centrale européenne)';

        return parent::configureActions($actions)->add(Actions::PAGE_INDEX, Action::new('refreshRates', $label, 'fa-solid fa-rotate')
            ->createAsGlobalAction()
            ->linkToCrudAction('refreshRates')
            ->setPermission(MarketplaceVoter::MANAGE)
            ->askConfirmation($this->rates->usesFixer()
                ? sprintf('Utiliser une des %d requêtes Fixer du mois (%d déjà utilisées) pour mettre à jour tous les taux ?', ExchangeRates::FIXER_QUOTA, $this->rates->requestsThisMonth())
                : 'Mettre à jour tous les taux depuis la Banque centrale européenne ?'));
    }

    /** One request for every pair; at most once an hour (ExchangeRates::MIN_INTERVAL). */
    #[AdminAction(path: '/refresh', methods: ['POST'])]
    public function refreshRates(): Response
    {
        try {
            $written = $this->rates->refresh();
            $this->addFlash('success', sprintf('%d taux mis à jour : %s.', \count($written), implode(', ', array_map(fn (Forex $forex) => $forex->getSource() . '/' . $forex->getTarget(), $written)) ?: '-'));
        } catch (ExchangeRatesException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToIndex();
    }

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
