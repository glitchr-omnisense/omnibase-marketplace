<?php

namespace Tests\Base\Marketplace\Http;

use Base\Marketplace\Controller\Admin\Crud\ProductCrudController;
use Base\Marketplace\Entity\Product;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * Every product screen of the back office - this bundle's and those of the
 * bundles that sell through it (omnibase/restaurant's dishes, set menus and
 * take-home dishes, omnibase/forge's hour packs and licences,
 * omnibase/classroom's resources for sale) - chooses its store without a
 * script: the stores are options of the page, and the one chosen is the
 * product's. Each screen is found in the routes of the host application, so
 * the bundles installed there are the ones checked.
 */
final class ProductScreensWithoutScriptTest extends MarketplaceKernelTestCase
{
    use BackOfficeFormTrait;
    use ShopFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInToTheBackOffice();
    }

    /** @return array<string, string> controller => the path of its "new" form */
    private function screens(): array
    {
        $screens = [];
        foreach (self::getContainer()->get('router')->getRouteCollection() as $route) {
            $controller = $route->getDefault('_controller');
            $controller = \is_array($controller) ? implode('::', $controller) : (string) $controller;
            if (!str_ends_with($controller, '::new')) {
                continue;
            }
            $class = substr($controller, 0, -\strlen('::new'));
            if (class_exists($class) && is_a($class, ProductCrudController::class, true) && !str_contains($route->getPath(), '{')) {
                $screens[$class] = $route->getPath();
            }
        }

        return $screens;
    }

    public function testEveryProductScreenTakesTheStoreChosenWithoutAScript(): void
    {
        $store = $this->store();
        $screens = $this->screens();
        self::assertArrayHasKey(ProductCrudController::class, $screens, 'this bundle\'s own screen is found');

        foreach ($screens as $controller => $path) {
            [$html] = $this->form($path);
            self::assertMatchesRegularExpression('~<option[^>]*value="'.$store->getId().'"[^>]*>\s*'.preg_quote($store->getTitle(), '~').'~', $this->select($html, 'parent'), $controller.': the stores are options of the page, before any script');

            $slug = 'sans-script-'.bin2hex(random_bytes(3));
            $typed = ['title' => 'Sans script', 'slug' => $slug, 'parent' => ['choice' => (string) $store->getId()], 'unitPrice' => '1900'];
            $response = $this->send($typed, $path);
            // A screen may ask more than these four fields (a pack's hours, a licence's seats): what it
            // refuses is said on those fields, never on the store - and once they are typed, it is kept.
            self::assertArrayNotHasKey('parent', $this->errors($response), $controller.': '.$this->said($response));
            if (422 === $response->getStatusCode()) {
                foreach (array_keys($this->errors($response)) as $field) {
                    if ('' !== $field && '?' !== $field) {
                        $typed[$field] = '1';
                    }
                }
                $response = $this->send($typed, $path);
            }
            self::assertSame(302, $response->getStatusCode(), $controller.': '.$this->said($response));

            $this->entityManager->clear();
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]);
            self::assertNotNull($product, $controller.': the product is kept');
            self::assertInstanceOf($controller::getEntityFqcn(), $product, $controller);
            self::assertSame($store->getId(), $product->getStore()?->getId(), $controller.': in the store chosen');
        }
    }
}
