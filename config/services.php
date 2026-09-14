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
 * gateways their market.payment_gateway tag, the Twig extension its tag,
 * controllers theirs. Entities, enums, models and attributes are not
 * services.
 *
 * The admin CRUD controllers are loaded only when base-bundle-admin is there.
 */
return function (ContainerConfigurator $configurator) {

    $src = dirname(__DIR__) . '/src';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->instanceof('Base\\Market\\Payment\\PaymentGatewayInterface')
        ->tag('market.payment_gateway');

    $services->load('Base\\Market\\', $src . '/')
        ->exclude([
            $src . '/Attribute/',
            $src . '/DependencyInjection/',
            $src . '/Entity/',
            $src . '/Enum/',
            $src . '/Event/',
            $src . '/Model/',
            $src . '/Controller/Admin/',
            $src . '/Service/*Exception.php',
            $src . '/Payment/*Exception.php',
            $src . '/Payment/PaymentResult.php',
            // Card payment needs omnipay/stripe; without it the gateway is left out.
            ...(class_exists('Omnipay\\Stripe\\CheckoutGateway') ? [] : [$src . '/Payment/StripeGateway.php']),
            $src . '/MarketBundle.php',
        ]);

    if (is_dir($src . '/Controller/Client')) {
        $services->load('Base\\Market\\Controller\\Client\\', $src . '/Controller/Client/')
            ->tag('controller.service_arguments');
    }

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController') && is_dir($src . '/Controller/Admin')) {
        $services->load('Base\\Market\\Controller\\Admin\\', $src . '/Controller/Admin/')
            ->tag('controller.service_arguments');
    }
};
