<?php

namespace Base\Market\Controller\Admin;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Market\Security\MarketplaceVoter;

/**
 * The marketplace screens' permissions (MarketplaceVoter), for every market
 * CRUD - used by AbstractMarketCrudController, and directly by a CRUD
 * controller that already extends another admin base (taxons, adapters):
 *
 *   reading     MARKET_VIEW: the creators, and the shops' owners;
 *   changing    the creators (the admin's default for new, edit, delete) -
 *               or, on a screen of VAT and prices (isPricing()), the shops'
 *               owners too: MARKET_PRICING.
 */
trait MarketAdminTrait
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityPermission(MarketplaceVoter::VIEW);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = parent::configureActions($actions);
        if ($this->isPricing()) {
            $actions->setPermissions(array_fill_keys([
                Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE,
                Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER,
            ], MarketplaceVoter::PRICING));
        }

        return $actions;
    }

    /** Whether this screen sets VAT or prices: its owners may change it (MARKET_PRICING). */
    protected function isPricing(): bool
    {
        return false;
    }
}
