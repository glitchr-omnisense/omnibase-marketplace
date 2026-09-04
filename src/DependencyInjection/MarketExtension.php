<?php

namespace Base\Market\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class MarketExtension extends AbstractBaseExtension
{
    public function getConfiguration(array $config, ContainerBuilder $container): MarketConfiguration
    {
        return new MarketConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');

        $processor = new Processor();
        $config = $processor->processConfiguration(new MarketConfiguration(), $configs);
    }
}
