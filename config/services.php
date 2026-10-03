<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * This file is part of the Glitchr package.
 *
 * (c) Marco Meyer <marco.meyer@glitchr.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Plain autowiring over src/, the way an application's own src/ is wired:
 * repositories get their doctrine.repository_service tag, the payment
 * gateways their marketplace.payment_gateway tag, the Twig extension its tag,
 * controllers theirs. Entities, enums, models and attributes are not
 * services.
 *
 * The admin CRUD controllers are loaded only when base-bundle-admin is there.
 * The company register and the VAT number checks ask Omnistate: the app
 * registers Omnistate\Bridge\Symfony\OmnistateBundle. Payments go through
 * glitchr/omnitrade's gateways and parcels through glitchr/omnibus's
 * carriers when those packages are installed (their bundles registered);
 * without them, the manual gateway and hand-priced shipping remain.
 */
return function (ContainerConfigurator $configurator) {

    $src = dirname(__DIR__) . '/src';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->instanceof('Base\\Marketplace\\Payment\\PaymentGatewayInterface')
        ->tag('marketplace.payment_gateway');

    $services->load('Base\\Marketplace\\', $src . '/')
        ->exclude([
            $src . '/Attribute/',
            $src . '/DependencyInjection/',
            $src . '/Entity/',
            $src . '/Enum/',
            $src . '/Event/',
            $src . '/Model/',
            // A constraint, not a service: its validator is one.
            $src . '/Validator/CompanyNumber.php',
            $src . '/Validator/VatNumber.php',
            $src . '/Controller/Admin/',
            $src . '/Service/*Exception.php',
            $src . '/Payment/*Exception.php',
            $src . '/Payment/PaymentResult.php',
            // The quotes' repositories' parent, not a repository of its own.
            $src . '/Quote/',
            // Registered in debug only, by MarketplaceExtension.
            $src . '/Payment/DevGateway.php',
            // The bridges to glitchr/omnitrade's gateways: built by
            // OmnitradeGateways, registered below when that package is there.
            $src . '/Payment/Omnitrade/OmnitradeGateway.php',
            ...(class_exists('Omnitrade\\Registry') ? [] : [$src . '/Payment/Omnitrade/']),
            // The buyer's return page and the providers' webhooks: only with omnitrade.
            ...(class_exists('Omnitrade\\Registry') ? [] : [$src . '/Controller/Client/PaymentController.php', $src . '/Console/StripeWebhookCommand.php']),
            // The catalogue read from a platform needs glitchr/omnitrade's catalogue (FetchProducts).
            ...(class_exists('Omnitrade\\Request\\FetchProducts') ? [] : [$src . '/Catalogue/', $src . '/Console/CatalogueSyncCommand.php']),
            $src . '/MarketplaceBundle.php',
            // A section of omnibase/admin's API keys page: only with that bundle.
            ...(interface_exists('Base\\Admin\\Settings\\SettingsSectionInterface') ? [] : [$src . '/Settings/']),
        ]);

    if (is_dir($src . '/Controller/Client')) {
        $services->load('Base\\Marketplace\\Controller\\Client\\', $src . '/Controller/Client/')
            ->exclude(class_exists('Omnitrade\\Registry') ? [] : [$src . '/Controller/Client/PaymentController.php'])
            ->tag('controller.service_arguments');
    }

    // The carriers (glitchr/omnibus) the shipping methods name: Shipping
    // books and tracks through Omnibus\Registry when the package is there.
    if (class_exists('Omnibus\\Registry')) {
        $services->get('Base\\Marketplace\\Service\\Shipping')
            ->arg('$carriers', service('Omnibus\\Registry')->nullOnInvalid());
    }

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController') && is_dir($src . '/Controller/Admin')) {
        $services->load('Base\\Marketplace\\Controller\\Admin\\', $src . '/Controller/Admin/')
            ->tag('controller.service_arguments');
    }
};
