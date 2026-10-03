<?php

namespace Tests\Base\Marketplace\Pricing;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Address\ShippingAddress;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Pricing\ExportExemption;
use PHPUnit\Framework\TestCase;

/** Goods leaving the EU carry no VAT, and the invoice says why. */
final class ExportExemptionTest extends TestCase
{
    private function order(?string $country, ?string $storeVat = 'FR40303265045'): Order
    {
        $address = $this->createMock(ShippingAddress::class);
        $address->method('getCountry')->willReturn($country);
        $store = $this->createMock(Store::class);
        $store->method('getVatNumber')->willReturn($storeVat);
        $order = $this->createMock(Order::class);
        $order->method('getShippingAddress')->willReturn(null === $country ? null : $address);
        $order->method('getStore')->willReturn($store);

        return $order;
    }

    public function testAnExportToJapanIsExempt(): void
    {
        self::assertSame(ExportExemption::MENTION, (new ExportExemption())->exempts($this->order('JP')));
        self::assertStringContainsString('262 I', ExportExemption::MENTION);
    }

    public function testInsideTheEuItPaysVat(): void
    {
        $exemption = new ExportExemption();
        self::assertNull($exemption->exempts($this->order('FR')));
        self::assertNull($exemption->exempts($this->order('DE')));
        self::assertNull($exemption->exempts($this->order('GR')));
        self::assertNull($exemption->exempts($this->order('EL')), 'Greece as VIES writes it');
        self::assertNull($exemption->exempts($this->order(null)), 'no address yet: no exemption');
    }

    public function testASellerOutsideTheEuExportsNothingHere(): void
    {
        self::assertNull((new ExportExemption('CH'))->exempts($this->order('JP', null)));
        self::assertSame(ExportExemption::MENTION_EU, (new ExportExemption())->exempts($this->order('US', 'DE811907980')));
        self::assertSame(ExportExemption::MENTION, (new ExportExemption('FR'))->exempts($this->order('GB', null)), 'the sender\'s country without a VAT number');
    }
}
