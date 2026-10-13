<?php

namespace Tests\Base\Marketplace\Http;

use Base\Marketplace\Entity\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * A product's page, and its store's shelf, as a visitor opens them - a
 * product with a price, and one that has none yet.
 */
final class ProductPageTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    private function open(string $path): Response
    {
        return self::$kernel->handle(Request::create($path));
    }

    private function said(Response $response): string
    {
        return $response->getStatusCode().' '.substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $response->getContent()))), 0, 1200);
    }

    private function page(Product $product): Response
    {
        return $this->open('/boutique/'.$this->store()->getSlug().'/'.$product->getSlug());
    }

    public function testAProductWithAPriceOpens(): void
    {
        $response = $this->page($this->goods('mug', 1900));

        self::assertSame(200, $response->getStatusCode(), $this->said($response));
        self::assertStringContainsString('19', (string) $response->getContent());
    }

    public function testAProductWithoutAPriceOpens(): void
    {
        $product = $this->goods('sur-devis', 0);

        $response = $this->page($product);
        self::assertSame(200, $response->getStatusCode(), $this->said($response));

        $shelf = $this->open('/boutique/'.$this->store()->getSlug());
        self::assertSame(200, $shelf->getStatusCode(), $this->said($shelf));
    }

    public function testAProductWhosePriceWasNeverWrittenOpens(): void
    {
        // A row as an import or an older schema left it: no price at all.
        $product = $this->goods('sans-prix', 1900);
        $property = new \ReflectionProperty(Product::class, 'unitPrice');
        $property->setValue($product, null);

        $response = $this->page($product);
        self::assertSame(200, $response->getStatusCode(), $this->said($response));

        $shelf = $this->open('/boutique/'.$this->store()->getSlug());
        self::assertSame(200, $shelf->getStatusCode(), $this->said($shelf));
    }

    public function testAShelfSaysOnRequestForWhatHasNoPriceAndNeverAPriceOfZero(): void
    {
        $this->goods('mug', 1900);
        $unpriced = $this->goods('sans-prix', 1900);
        (new \ReflectionProperty(Product::class, 'unitPrice'))->setValue($unpriced, null);

        $shelf = $this->open('/boutique/'.$this->store()->getSlug());
        self::assertSame(200, $shelf->getStatusCode(), $this->said($shelf));

        $html = (string) $shelf->getContent();
        $onRequest = self::getContainer()->get('translator')->trans('product.price_on_request', [], 'marketplace');
        self::assertNotSame('product.price_on_request', $onRequest, 'the text is translated');
        self::assertSame(1, substr_count($html, 'marketplace-price-request'), 'one card says "on request": the one without a price');
        self::assertStringContainsString($onRequest, $html);
        self::assertSame(1, substr_count($html, '<span class="marketplace-price">'), 'and the other shows its price');
        self::assertDoesNotMatchRegularExpression('/\b0[,.]00/', strip_tags($html), 'a price of zero is not shown');
    }
}
