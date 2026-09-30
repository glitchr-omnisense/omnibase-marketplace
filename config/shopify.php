<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Base\Marketplace\Shopify\Api\AdminApi;
use Base\Marketplace\Shopify\Api\Endpoint;
use Base\Marketplace\Shopify\Api\GraphQL;
use Base\Marketplace\Shopify\Api\StorefrontApi;
use Base\Marketplace\Shopify\Export\OrderPaidSubscriber;
use Base\Marketplace\Shopify\Export\PushOrderHandler;

/*
 * This file is part of the Glitchr package.
 *
 * (c) Marco Meyer <marco.meyer@glitchr.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The optional Shopify integration.
 *
 * Loaded by MarketplaceExtension::load() and ONLY when marketplace.shopify.enabled is a
 * literal true, so nothing here exists in an application that does not ask for
 * it - config/services.php excludes src/Shopify/ from its own recursive load
 * for exactly that reason.
 *
 * The guard below is the second half of the same idea: symfony/http-client is
 * the host application's dependency, not this bundle's, and without it none of
 * this can be built. Same shape as the omnipay/stripe check in services.php.
 */
if (!interface_exists(\Symfony\Contracts\HttpClient\HttpClientInterface::class)
    || !class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
    return static function (ContainerConfigurator $configurator): void {
    };
}

return static function (ContainerConfigurator $configurator): void {

    $src = dirname(__DIR__) . '/src/Shopify';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    // The shop itself, as one object rather than five constructor arguments
    // threaded through every service. Serving more than one shop later is a
    // change to this definition and nowhere else.
    $services->set(Endpoint::class)
        ->args([
            '%marketplace.shopify.shop_domain%',
            '%marketplace.shopify.api_version%',
            '%marketplace.shopify.admin_token%',
            '%marketplace.shopify.storefront_token%',
            '%marketplace.shopify.webhook_secret%',
            '%marketplace.shopify.timeout%',
        ]);

    $services->set(GraphQL::class);
    $services->set(AdminApi::class);
    $services->set(StorefrontApi::class);

    $services->load('Base\\Marketplace\\Shopify\\', $src . '/')
        ->exclude([
            $src . '/Api/',
            $src . '/Entity/',
            // Value objects and the message, not services.
            $src . '/Catalogue/ProductData.php',
            $src . '/Catalogue/Query.php',
            $src . '/Export/PushOrderMessage.php',
        ]);

    // The repository is a service like any other in this bundle; the entity it
    // serves is mapped by MarketplaceExtension::prepend(), under the same flag.
    $services->load('Base\\Marketplace\\Shopify\\Repository\\', $src . '/Repository/');

    // An optional collaborator, the way wikidoc takes its index cache: without
    // a pool the replay guard simply says "not seen" and the integration falls
    // back on the idempotency it has anyway.
    $services->set(\Base\Marketplace\Shopify\Webhook\ReplayGuard::class)
        ->args([service('cache.app')->nullOnInvalid()]);

    // Messenger is how a paid order reaches Shopify without a network call
    // inside somebody else's webhook. Without the component the export role
    // simply has no listener and no handler - the other two roles are
    // unaffected.
    if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
        $services->remove(OrderPaidSubscriber::class);
        $services->remove(PushOrderHandler::class);
    }
};
