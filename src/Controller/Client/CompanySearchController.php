<?php

namespace Base\Market\Controller\Client;

/**
 * Omnistate's company search, GET /omnistate/company/search?q=: routed here
 * so every shop has it without importing Omnistate's controllers (the
 * Store form's company field asks it). Its route is the parent's.
 */
final class CompanySearchController extends \Omnistate\Bridge\Symfony\Controller\CompanySearchController
{
}
