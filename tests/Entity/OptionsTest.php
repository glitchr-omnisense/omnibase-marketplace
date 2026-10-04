<?php

namespace Tests\Base\Marketplace\Entity;

use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Option;
use Base\Marketplace\Entity\Product\OptionGroup;
use Base\Marketplace\Entity\Product\Variant;
use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\ProductOptions;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/** A product's options: one of a group or several, a minimum, a maximum, what they add to the line. */
final class OptionsTest extends TestCase
{
    private int $ids = 0;

    private function product(int $price = 1200, string $class = Product::class): Product
    {
        // Its name is a translation's (a database's): the test gives it one.
        $product = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['__toString', 'getReference'])->getMock();
        $product->method('__toString')->willReturn('product');
        $product->method('getReference')->willReturn('PRO0001');
        (new \ReflectionProperty(Product::class, 'unitPrice'))->setValue($product, $price);
        (new \ReflectionProperty(Product::class, 'currency'))->setValue($product, 'EUR');
        (new \ReflectionProperty(Product::class, 'variants'))->setValue($product, new ArrayCollection());

        return $product;
    }

    private function group(Product $product, string $label, bool $multiple, int $minimum, ?int $maximum, array $options): OptionGroup
    {
        $group = new OptionGroup($label, $multiple, $minimum, $maximum);
        (new \ReflectionProperty(OptionGroup::class, 'id'))->setValue($group, ++$this->ids);
        foreach ($options as $name => [$price, $default]) {
            $option = new Option($name, $price, $default);
            (new \ReflectionProperty(Option::class, 'id'))->setValue($option, ++$this->ids);
            $group->addOption($option);
        }
        $product->addOptionGroup($group);

        return $group;
    }

    private function ramen(): array
    {
        $ramen = $this->product(1200);
        $cooking = $this->group($ramen, 'Cuisson des nouilles', false, 1, null, ['Fermes' => [0, false], 'Normales' => [0, true], 'Tendres' => [0, false]]);
        $extras = $this->group($ramen, 'Suppléments', true, 0, 2, ['Œuf mollet' => [150, false], 'Chashu' => [300, false], 'Nori' => [100, false]]);

        return [$ramen, $cooking, $extras];
    }

    private function id(OptionGroup $group, string $label): int
    {
        foreach ($group->getOptions() as $option) {
            if ($option->getLabel() === $label) {
                return $option->getId();
            }
        }
        throw new \LogicException($label);
    }

    public function testNothingChosenTakesThePreselectedOptions(): void
    {
        [$ramen] = $this->ramen();
        $selection = (new ProductOptions())->select($ramen);

        self::assertSame(['Normales'], $selection->labels());
        self::assertSame(0, $selection->surcharge());
    }

    public function testTheExtrasAddToTheLinesUnitPrice(): void
    {
        [$ramen, $cooking, $extras] = $this->ramen();
        $selection = (new ProductOptions())->select($ramen, [$this->id($extras, 'Chashu'), $this->id($cooking, 'Fermes'), (string) $this->id($extras, 'Œuf mollet')]);

        self::assertSame(['Fermes', 'Œuf mollet', 'Chashu'], $selection->labels(), 'in the order of the groups and of their options');
        self::assertSame(450, $selection->surcharge());

        $line = new OrderItem($ramen, 2);
        $line->applyOptions($selection);
        self::assertSame(1650, $line->getUnitPrice());
        self::assertSame(3300, $line->getGrossPrice());
        self::assertSame(450, $line->getOptionsSurcharge());
        self::assertSame('Fermes, Œuf mollet, Chashu', $line->getOptionsLabel());
        self::assertSame($selection->key(), $line->getOptionsKey());
        self::assertSame('Suppléments', $line->getOptions()[1]['group_label']);
    }

    public function testALineWithoutOptionsIsUntouched(): void
    {
        $line = new OrderItem($this->product(900), 1);
        self::assertSame([], $line->getOptions());
        self::assertSame('', $line->getOptionsKey());
        self::assertSame(900, $line->getUnitPrice());
    }

    public function testASingleChoiceGroupTakesOne(): void
    {
        [$ramen, $cooking] = $this->ramen();
        $this->expectException(CartException::class);
        $this->expectExceptionMessage('options.error.single');
        (new ProductOptions())->select($ramen, [$this->id($cooking, 'Fermes'), $this->id($cooking, 'Tendres')]);
    }

    public function testTheMaximumOfAMultipleGroup(): void
    {
        [$ramen, , $extras] = $this->ramen();
        $this->expectExceptionMessage('options.error.maximum');
        (new ProductOptions())->select($ramen, array_map(fn (Option $o) => $o->getId(), $extras->getOptions()->toArray()));
    }

    public function testARequiredGroupWithoutPreselection(): void
    {
        $steak = $this->product(2400);
        $this->group($steak, 'Cuisson', false, 1, null, ['Saignant' => [0, false], 'À point' => [0, false]]);
        $this->expectExceptionMessage('options.error.minimum');
        (new ProductOptions())->select($steak);
    }

    public function testAnotherProductsOptionAndAnOptionRunOut(): void
    {
        [$ramen, , $extras] = $this->ramen();
        $steak = $this->product(2400);
        $cooking = $this->group($steak, 'Cuisson', false, 0, null, ['Saignant' => [0, false]]);
        try {
            (new ProductOptions())->select($ramen, [$this->id($cooking, 'Saignant')]);
            self::fail('an option of another product was taken');
        } catch (CartException $e) {
            self::assertSame('options.error.unknown', $e->getMessage());
        }

        $extras->getOptions()->first()->setAvailable(false);
        $this->expectExceptionMessage('options.error.unavailable');
        (new ProductOptions())->select($ramen, [$this->id($extras, 'Œuf mollet')]);
    }

    public function testAVariantOffersItsPrincipalsOptions(): void
    {
        [$ramen] = $this->ramen();
        $large = $this->product(1500, Variant::class);
        (new \ReflectionProperty(\Base\Entity\Thread::class, 'parent'))->setValue($large, $ramen);

        self::assertTrue($large->hasOptions());
        self::assertCount(2, $large->getOptionGroups());
    }

    public function testThePriceRangeIsTheVariantsForSale(): void
    {
        $banner = $this->product(0);
        self::assertSame([0, 0], $banner->getPriceRange());
        self::assertFalse($banner->isPricedFrom());

        $variants = [];
        foreach ([20000 => true, 13000 => true, 45000 => true, 9000 => false] as $price => $forSale) {
            $variant = $this->getMockBuilder(Variant::class)->disableOriginalConstructor()->onlyMethods(['isForSell', 'getUnitPrice'])->getMock();
            $variant->method('isForSell')->willReturn($forSale);
            $variant->method('getUnitPrice')->willReturn($price);
            $variants[] = $variant;
        }
        (new \ReflectionProperty(Product::class, 'variants'))->setValue($banner, new ArrayCollection($variants));

        self::assertSame([13000, 45000], $banner->getPriceRange(), 'the variant no longer sold is left out');
        self::assertSame(13000, $banner->getStartingPrice());
        self::assertTrue($banner->isPricedFrom());
    }

    public function testAStartingPriceTypedByHand(): void
    {
        $sign = $this->product(0);
        $sign->setPriceFrom(30000);

        self::assertSame(30000, $sign->getStartingPrice());
        self::assertTrue($sign->isPricedFrom());
        self::assertSame([0, 0], $sign->getPriceRange(), 'shown, never charged: the range is the real prices');
        self::assertNull($sign->setPriceFrom(null)->getPriceFrom());
    }
}
