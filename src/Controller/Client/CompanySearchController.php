<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Security\MarketplaceVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Omnistate's company search, GET /omnistate/company/search?q=: routed here
 * so every shop has it without importing Omnistate's controllers (the
 * Store form's company field asks it). Its route is the parent's.
 *
 * For the back office only (MARKETPLACE_VIEW: creators and store owners): open,
 * any visitor could search the State's register through the shop and spend
 * its rate limit (about seven calls a second, per server).
 */
#[IsGranted(MarketplaceVoter::VIEW)]
final class CompanySearchController extends \Omnistate\Bridge\Symfony\Controller\CompanySearchController
{
}
