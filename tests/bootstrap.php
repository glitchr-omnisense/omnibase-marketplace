<?php

// Standalone (composer install in this checkout) or inside a host application
// (vendor/glitchr/base-bundle-market): whichever autoloader exists is used,
// and the test namespace is registered by hand because a host's autoloader
// never reads a dependency's autoload-dev.
$candidates = [__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php'];
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Tests\\Base\\Market\\', __DIR__);
        // Prepended: the classes under test are this checkout's, even when a
        // host application has an installed copy of the bundle too.
        $loader->addPsr4('Base\\Market\\', __DIR__.'/../src', true);

        return;
    }
}

throw new RuntimeException('No autoloader found: run composer install in this checkout or install the bundle in an application.');
