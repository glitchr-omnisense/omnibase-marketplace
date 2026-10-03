<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Sales\Region;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Service\Shipping;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** The shop's parcels go where its regions say; elsewhere is a quote. */
final class ShippingZoneTest extends TestCase
{
    private function region(array $countries, bool $enabled = true): Region
    {
        $region = $this->createMock(Region::class);
        $region->method('getCountries')->willReturn($countries);
        $region->method('isEnabled')->willReturn($enabled);

        return $region;
    }

    private function order(array $regions, bool $quoted = false): Order
    {
        $store = $this->createMock(Store::class);
        $store->method('getRegions')->willReturn(new ArrayCollection($regions));
        $order = $this->createMock(Order::class);
        $order->method('getStore')->willReturn($store);
        $order->method('getRegion')->willReturn($regions[0] ?? null);
        $order->method('isQuoted')->willReturn($quoted);
        $order->method('getItems')->willReturn(new ArrayCollection());

        return $order;
    }

    public function testFranceAndTheEuButNotJapan(): void
    {
        $shipping = new Shipping($this->createMock(EntityManagerInterface::class));
        $order = $this->order([$this->region(['FR']), $this->region(['DE', 'BE', 'IT'])]);

        self::assertTrue($shipping->deliversTo($order, 'fr'));
        self::assertTrue($shipping->deliversTo($order, 'BE'));
        self::assertFalse($shipping->deliversTo($order, 'JP'));
        self::assertSame(Shipping::OUT_OF_ZONE, $shipping->apply($order, ['name' => 'K. Sato', 'street' => '1-2-3 Ginza', 'zip' => '104-0061', 'city' => 'Tokyo', 'country' => 'JP'], 1));
    }

    public function testNoCountryListedGoesEverywhereAndAQuoteGoesWhereItSays(): void
    {
        $shipping = new Shipping($this->createMock(EntityManagerInterface::class));
        self::assertTrue($shipping->deliversTo($this->order([$this->region([])]), 'JP'));
        self::assertTrue($shipping->deliversTo($this->order([$this->region(['FR'], enabled: false)]), 'JP'), 'a region switched off lists nothing');
        $quoted = $this->order([$this->region(['FR'])], quoted: true);
        self::assertTrue($shipping->deliversTo($quoted, 'JP'));
        self::assertFalse($shipping->needsShipping($quoted), 'its transport is in its lines');
    }
}
