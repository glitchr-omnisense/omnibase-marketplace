<?php

namespace Tests\Base\Marketplace\Service;

use Base\Entity\Layout\Attribute\Adapter\Common\AbstractAdapter;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\AttributeSet;
use Base\Marketplace\Entity\Product\AttributeSet\Field;
use Base\Marketplace\Service\AttributeFilters;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** The list's filters, from the attributes a set marks filterable. */
final class AttributeFiltersTest extends TestCase
{
    private function adapter(string $code, string $label): AbstractAdapter
    {
        $adapter = $this->createMock(AbstractAdapter::class);
        $adapter->method('getCode')->willReturn($code);
        $adapter->method('__toString')->willReturn($label);

        return $adapter;
    }

    private function wine(array $values): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getAttributeValue')->willReturnCallback(fn (string $code) => $values[$code] ?? null);
        $product->method('getVariants')->willReturn(new ArrayCollection());

        return $product;
    }

    private function set(): AttributeSet
    {
        $set = new AttributeSet('Vin');
        $set->addField(new Field($this->adapter('colour', 'Couleur'), filterable: true));
        $set->addField(new Field($this->adapter('grapes', 'Cépages'), filterable: true));
        $set->addField(new Field($this->adapter('alcohol', 'Degré'), filterable: true, filter: Field::FILTER_RANGE, unit: '% vol.'));
        $set->addField(new Field($this->adapter('notes', 'Dégustation')));

        return $set;
    }

    public function testFacetsCountTheValuesMetAndTheRanges(): void
    {
        $wines = [
            $this->wine(['colour' => 'Rouge', 'grapes' => 'Merlot, Cabernet franc', 'alcohol' => '14']),
            $this->wine(['colour' => 'Rouge', 'grapes' => 'Pinot noir', 'alcohol' => '13']),
            $this->wine(['colour' => 'Blanc', 'grapes' => 'Chardonnay', 'alcohol' => '12.5']),
        ];
        $facets = (new AttributeFilters())->facets($wines, $this->set());

        self::assertSame(['colour', 'grapes', 'alcohol'], array_column($facets, 'code'), 'only the filterable fields');
        self::assertSame(['Blanc' => 1, 'Rouge' => 2], $facets[0]['values']);
        self::assertSame(['Cabernet franc' => 1, 'Chardonnay' => 1, 'Merlot' => 1, 'Pinot noir' => 1], $facets[1]['values'], 'a list counts once per item');
        self::assertSame([12.5, 14.0], [$facets[2]['min'], $facets[2]['max']]);
        self::assertSame('% vol.', $facets[2]['unit']);
    }

    public function testTheChoiceComesFromTheQueryStringAndNarrowsTheList(): void
    {
        $filters = new AttributeFilters();
        $request = new Request(['f' => ['colour' => ['rouge'], 'alcohol' => ['min' => '13.5'], 'grapes' => ['']]]);
        $selection = $filters->selection($request);
        self::assertSame(['colour' => ['rouge'], 'alcohol' => ['min' => 13.5]], $selection);

        $pomerol = $this->wine(['colour' => 'Rouge', 'alcohol' => '14']);
        $bourgogne = $this->wine(['colour' => 'Rouge', 'alcohol' => '13']);
        $chablis = $this->wine(['colour' => 'Blanc', 'alcohol' => '14']);
        self::assertSame([$pomerol], $filters->apply([$pomerol, $bourgogne, $chablis], $selection));
        self::assertSame([$pomerol, $chablis], $filters->apply([$pomerol, $bourgogne, $chablis], ['alcohol' => ['min' => 13.5, 'max' => 15]]));
    }
}
