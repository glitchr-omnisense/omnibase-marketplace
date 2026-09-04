<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return function (ContainerConfigurator $container) {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public(false);

    // Repositories and the marketplace service are picked up by the app's own
    // resource block today; declared explicitly here as they are moved, so the
    // bundle stands on its own rather than relying on the host app's
    // config/services.yaml globs.
};
