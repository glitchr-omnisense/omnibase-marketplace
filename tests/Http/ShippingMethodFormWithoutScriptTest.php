<?php

namespace Tests\Base\Marketplace\Http;

use Base\Marketplace\Entity\Order\Method\ShippingMethod;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * A shipping method typed in the back office, its form sent as the page
 * prints it: a number left empty - its price, its delays - is said on its
 * field, where the page stopped on a type error; a price of zero is a price
 * (a free delivery).
 */
final class ShippingMethodFormWithoutScriptTest extends MarketplaceKernelTestCase
{
    use BackOfficeFormTrait;
    use ShopFixtureTrait;

    private const NEW = '/admin/shipping-methods/new';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInToTheBackOffice();
    }

    /**
     * @param array<string, mixed> $typed
     *
     * @return array<string, mixed> a method as somebody types it, the fields given here in place of theirs
     */
    private function typed(string $slug, array $typed = []): array
    {
        return $typed + ['label' => 'Colis suivi', 'slug' => $slug, 'typeRate' => 'RATE_FLAT', 'unitPrice' => '690', 'deliveryTime' => '2', 'shippingDelay' => '1'];
    }

    private function kept(string $slug): ?ShippingMethod
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['slug' => $slug]);
    }

    public function testAMethodIsCreatedAndItsPagesOpen(): void
    {
        $slug = 'colis-'.bin2hex(random_bytes(3));

        $response = $this->send($this->typed($slug), self::NEW);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));

        $method = $this->kept($slug);
        self::assertNotNull($method, 'the method is kept');
        self::assertSame([690, 2, 1], [$method->getUnitPrice(), $method->getDeliveryTime(), $method->getShippingDelay()]);
        foreach (['/admin/shipping-methods/'.$method->getId().'/edit', '/admin/shipping-methods'] as $path) {
            $page = $this->open($path);
            self::assertSame(200, $page->getStatusCode(), $path.': '.$this->said($page));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function numbers(): iterable
    {
        yield 'its price' => ['unitPrice'];
        yield 'its delivery time' => ['deliveryTime'];
        yield 'its shipping delay' => ['shippingDelay'];
    }

    /** @dataProvider numbers */
    public function testANumberLeftEmptyIsRefusedOnItsField(string $field): void
    {
        $slug = 'sans-'.strtolower($field).'-'.bin2hex(random_bytes(3));

        $response = $this->send($this->typed($slug, [$field => '']), self::NEW);

        // The form given back with what is wrong, not an error page - and nothing kept.
        self::assertSame(422, $response->getStatusCode(), $this->said($response));
        self::assertSame([$field], array_keys($this->errors($response)), 'that field says it, and it alone: '.$this->said($response));
        self::assertNull($this->kept($slug));
    }

    public function testAPriceOfZeroIsAPrice(): void
    {
        $slug = 'offert-'.bin2hex(random_bytes(3));

        $response = $this->send($this->typed($slug, ['unitPrice' => '0', 'deliveryTime' => '0', 'shippingDelay' => '0']), self::NEW);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));
        self::assertSame([0, 0, 0], [$this->kept($slug)?->getUnitPrice(), $this->kept($slug)?->getDeliveryTime(), $this->kept($slug)?->getShippingDelay()]);
    }

    public function testEmptyingAMethodsPriceIsRefusedAndItsPriceKept(): void
    {
        $slug = 'retrait-'.bin2hex(random_bytes(3));
        self::assertSame(302, $this->send($this->typed($slug), self::NEW)->getStatusCode());
        $path = '/admin/shipping-methods/'.$slug.'/edit';

        // Its form sent back untouched changes nothing...
        $response = $this->send([], $path);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));
        self::assertSame(690, $this->kept($slug)?->getUnitPrice());

        // ...and with its price emptied it is given back, the price as it was.
        $response = $this->send(['unitPrice' => ''], $path);
        self::assertSame(422, $response->getStatusCode(), $this->said($response));
        self::assertSame(['unitPrice'], array_keys($this->errors($response)), $this->said($response));
        self::assertSame(690, $this->kept($slug)?->getUnitPrice());
    }
}
