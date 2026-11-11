<?php

// omnisense/omnibill and omnisense/contracts it builds on, when the host has
// them not (omnisense/omnibase's harness image has neither): the family's
// checkout from OMNIBILL_PATH, beside the workspace (~/Sites/omnibill) or
// mounted at /srv/omnibill - its core and the e-invoices' gateways, email and
// afnor -, contracts from OMNISENSE_CONTRACTS, ~/Sites/omnisense-contracts or
// /srv/omnisense-contracts. Without them the invoices' transmission tests
// skip. Asked of the loader's prefixes, not class_exists(): a class looked
// for in vain is remembered missing.
//
// Required by tests/bootstrap.php, with the host's Composer loader. Given
// too to the console processes a test starts (php -d
// auto_prepend_file=tests/omnibill.php bin/console ...) once the suite has
// registered the family, since the container they share was compiled with
// it: there, before any autoloader exists, it registers its own for those
// namespaces - requiring vendor/autoload.php first would make
// autoload_runtime.php return at once.

$loader ??= null;
$composer = $loader instanceof Composer\Autoload\ClassLoader ? $loader : null;
$prefixes = null !== $composer ? $composer->getPrefixesPsr4() : [];

$omnibill = [getenv('OMNIBILL_PATH') ?: null, __DIR__.'/../../../omnibill', '/srv/omnibill'];
$sources = [
    'Omnisense\\Contracts\\' => [[getenv('OMNISENSE_CONTRACTS') ?: null, __DIR__.'/../../../omnisense-contracts', '/srv/omnisense-contracts'], ''],
    'Omnibill\\' => [$omnibill, 'core'],
    'Omnibill\\Afnor\\' => [$omnibill, 'afnor'],
    'Omnibill\\Email\\' => [$omnibill, 'email'],
];
$added = false;
foreach ($sources as $namespace => [$roots, $package]) {
    if (isset($prefixes[$namespace])) {
        continue;
    }
    foreach ($roots as $root) {
        $directory = null === $root ? null : rtrim($root.'/'.$package, '/');
        if (null === $directory || !is_file($directory.'/composer.json')) {
            continue;
        }
        if (null !== $composer) {
            $composer->addPsr4($namespace, $directory);
        } else {
            spl_autoload_register(static function (string $class) use ($namespace, $directory): void {
                if (str_starts_with($class, $namespace) && is_file($file = $directory.'/'.strtr(substr($class, \strlen($namespace)), '\\', '/').'.php')) {
                    require $file;
                }
            });
        }
        $added = $added || 'Omnibill\\' === $namespace;
        break;
    }
}

if ($added && null !== $composer && !defined('MARKETPLACE_TESTS_OMNIBILL')) {
    define('MARKETPLACE_TESTS_OMNIBILL', __FILE__);
    // In the harness, the test container was compiled by doctrine:schema:create, before omnibill was
    // known (config/services.php asks class_exists()): compiled again, with the family. Only there:
    // a host that has omnibill compiled it with it.
    $cache = dirname((string) (new ReflectionClass($composer))->getFileName(), 3).'/var/cache/test';
    if (getenv('HARNESS_SUITE_BOOTSTRAP') && is_dir($cache)) {
        (new Symfony\Component\Filesystem\Filesystem())->remove($cache);
        // Compiled here, its warmers' words kept out of the first test's output.
        $kernel = $_SERVER['KERNEL_CLASS'] ?? $_ENV['KERNEL_CLASS'] ?? 'App\\Kernel';
        if (class_exists($kernel)) {
            ob_start();
            try {
                $booted = new $kernel('test', filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? true, \FILTER_VALIDATE_BOOL));
                $booted->boot();
                $booted->shutdown();
            } finally {
                ob_end_clean();
            }
        }
    }
}
