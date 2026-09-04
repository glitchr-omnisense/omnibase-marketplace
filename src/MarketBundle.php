<?php

namespace Base\Market;

use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class MarketBundle extends AbstractBaseBundle
{
    // Own singleton storage rather than sharing AbstractBaseBundle's - see
    // that class's constructor for why every concrete bundle needs this.
    use SingletonTrait;

    // The trait's protected no-op __construct() wins over the inherited one
    // the moment the trait is used here, which hides the real registration
    // logic and makes `new MarketBundle()` in bundles.php fatal. Redeclaring
    // it public and delegating restores both. Same reasoning as AdminBundle.
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Modern bundle layout: the class lives in src/, the bundle root is the
     * package root - so TwigBundle picks up ./templates as @Market.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // The App\-wins override convention BaseBundle uses for
        // Entity/Repository/Enum/Form: every concrete Base\Market\Entity\*
        // is aliased onto App\Entity\Marketplace\* UNLESS the app already
        // declares a real class there. That is what lets this app keep its
        // own Wallpaper as a Product subclass while everything generic
        // lives here.
        $this->setMapping($this->getPath() . '/src/Entity', 'Base\Market\Entity', 'App\Entity\Marketplace');
        $this->setMapping($this->getPath() . '/src/Repository', 'Base\Market\Repository', 'App\Repository\Marketplace');
    }
}
