<?php

namespace Base\Marketplace\Controller\Admin;

use Base\Admin\Controller\AbstractCrudController;

/**
 * Every marketplace screen of the back office: products, stores, orders,
 * taxes, exchange rates... For the creators (`marketplace.admin_role`, by default
 * ROLE_SUPERADMIN; ROLE_EDITOR inherits it), and for a shop's owners to read
 * (MarketplaceVoter): an application's other staff keep their own screens (a
 * kitchen, a till) without the catalogue, the prices or the money.
 *
 * The admin bundle checks this permission on every action of the CRUD and
 * hides the menu entries and dashboard cards that lead to it.
 */
abstract class AbstractMarketplaceCrudController extends AbstractCrudController
{
    use MarketplaceAdminTrait;
}
