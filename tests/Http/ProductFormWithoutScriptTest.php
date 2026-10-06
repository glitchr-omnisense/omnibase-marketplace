<?php

namespace Tests\Base\Marketplace\Http;

use Base\Marketplace\Entity\Product;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * A product typed in the back office as its form is sent when no script
 * ran - a browser that blocks them, a page whose script failed: the fields
 * as the HTML prints them, nothing a script would have filled.
 */
final class ProductFormWithoutScriptTest extends MarketplaceKernelTestCase
{
    use BackOfficeFormTrait;
    use ShopFixtureTrait;

    private const NEW = '/admin/products/new';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInToTheBackOffice();
    }

    private function kept(string $slug): ?Product
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]);
    }

    public function testTheStoreIsChosenFromAListThatIsInThePage(): void
    {
        $store = $this->store();
        [$html] = $this->form(self::NEW);

        $select = $this->select($html, 'parent');
        self::assertNotSame('', $select, 'the store is a <select> of the form: '.(preg_match('~.{0,300}crud_form_parent.{0,1500}~s', $html, $near) ? $near[0] : 'no field at all'));
        self::assertMatchesRegularExpression('~<option[^>]*value="'.$store->getId().'"[^>]*>\s*'.preg_quote($store->getTitle(), '~').'~', $select, 'the stores are options of it, before any script: '.substr($select, 0, 1500));
    }

    public function testAProductIsCreatedInTheStoreChosenWithoutAScript(): void
    {
        $store = $this->store();
        $slug = 'sans-script-'.bin2hex(random_bytes(3));

        // The option chosen, sent as the <select> names it.
        $response = $this->send(['title' => 'Mug sans script', 'slug' => $slug, 'parent' => ['choice' => (string) $store->getId()], 'unitPrice' => '1900'], self::NEW);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));

        $product = $this->kept($slug);
        self::assertNotNull($product, 'the product is kept');
        self::assertSame($store->getId(), $product->getStore()?->getId(), 'in the store chosen');
        self::assertSame(1900, $product->getUnitPrice());

        // Its pages open: the form again, with its store selected, and the shop's.
        $edit = $this->open('/admin/products/'.$product->getId().'/edit');
        self::assertSame(200, $edit->getStatusCode(), $this->said($edit));
        self::assertMatchesRegularExpression('~<option[^>]*value="'.$store->getId().'"[^>]*selected~', $this->select((string) $edit->getContent(), 'parent'));
        $page = $this->open('/boutique/'.$store->getSlug().'/'.$slug);
        self::assertSame(200, $page->getStatusCode(), $this->said($page));
    }

    public function testAProductTypedWithoutAPriceIsRefusedOnItsField(): void
    {
        $store = $this->store();
        $slug = 'sans-prix-'.bin2hex(random_bytes(3));

        $response = $this->send(['title' => 'Sur devis', 'slug' => $slug, 'parent' => ['choice' => (string) $store->getId()], 'unitPrice' => ''], self::NEW);

        // The form given back with what is wrong, not an error page - and nothing kept.
        self::assertSame(422, $response->getStatusCode(), $this->said($response));
        self::assertSame(['unitPrice'], array_keys($this->errors($response)), 'the price field says it, and it alone: '.$this->said($response));
        self::assertNull($this->kept($slug));
    }

    public function testAPriceOfZeroIsAPrice(): void
    {
        $store = $this->store();
        $slug = 'sur-devis-'.bin2hex(random_bytes(3));

        $response = $this->send(['title' => 'Sur devis', 'slug' => $slug, 'parent' => ['choice' => (string) $store->getId()], 'unitPrice' => '0'], self::NEW);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));

        $product = $this->kept($slug);
        self::assertNotNull($product);
        self::assertSame(0, $product->getUnitPrice());
        foreach (['/admin/products/'.$product->getId().'/edit', '/admin/products', '/boutique/'.$store->getSlug().'/'.$slug, '/boutique/'.$store->getSlug()] as $path) {
            $page = $this->open($path);
            self::assertSame(200, $page->getStatusCode(), $path.': '.$this->said($page));
        }
    }

    public function testEmptyingAProductsPriceIsRefusedAndItsPriceKept(): void
    {
        $product = $this->goods('mug', 1900);
        [$id, $slug] = [$product->getId(), $product->getSlug()];
        $path = '/admin/products/'.$slug.'/edit';
        $read = function () use ($id): Product {
            $this->entityManager->clear();

            return $this->entityManager->find(Product::class, $id);
        };

        // Its form sent back untouched changes nothing...
        $response = $this->send([], $path);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));
        self::assertSame([$slug, 1900, $this->store()->getId()], [$read()->getSlug(), $read()->getUnitPrice(), $read()->getStore()?->getId()]);

        // ...and with its price emptied it is given back, the price as it was.
        $response = $this->send(['unitPrice' => ''], $path);
        self::assertSame(422, $response->getStatusCode(), $this->said($response));
        self::assertSame(['unitPrice'], array_keys($this->errors($response)), $this->said($response));
        self::assertSame(1900, $read()->getUnitPrice());
    }

    public function testAProductSentWithNoStoreChosenIsNotAnErrorPage(): void
    {
        $this->store();
        $slug = 'sans-boutique-'.bin2hex(random_bytes(3));

        // The <select> left on its first line, "choose".
        $response = $this->send(['title' => 'Sans boutique', 'slug' => $slug, 'unitPrice' => '1900'], self::NEW);
        self::assertSame(422, $response->getStatusCode(), $this->said($response));
        self::assertSame(['parent'], array_keys($this->errors($response)), $this->said($response));
        self::assertNull($this->kept($slug));
    }
}
