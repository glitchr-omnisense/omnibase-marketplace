<?php

namespace Tests\Base\Marketplace\Catalogue;

use Base\Marketplace\Catalogue\PlatformSynchronizer;
use Base\Marketplace\Entity\Catalogue\PlatformLink;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\ProductAvailability;
use Base\Marketplace\Repository\Catalogue\PlatformLinkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Model\Money;
use Omnitrade\Model\Offer;
use Omnitrade\Model\Product as RemoteProduct;
use Omnitrade\Model\ProductVariant;
use Omnitrade\Model\Stock;
use PHPUnit\Framework\TestCase;

/**
 * The platform's catalogue copied here, the parts that need no database:
 * what changed is told by the fingerprint of the owned fields, a product
 * gone there is taken off sale, a stock level lands on its variant. The
 * whole import, on a real database, is the applications' test (wineseller's
 * CatalogueSyncTest, on a gateway of its own).
 */
final class PlatformSynchronizerTest extends TestCase
{
    private function remote(int $price = 2900, ?string $description = 'Un pomerol', array $attributes = ['appellation' => 'Pomerol']): RemoteProduct
    {
        return new RemoteProduct('fake', 'p1', 'Château Exemple 2019', $description, 'chateau-exemple-2019', 'Château Exemple', attributes: $attributes,
            variants: [new ProductVariant('v1', null, 'CEX19', offers: [new Offer(Money::of($price, 'EUR'))], stock: new Stock('v1', 24))]);
    }

    public function testTheFingerprintFollowsOnlyTheOwnedFields(): void
    {
        $priceOnly = new PlatformSynchronizer($this->createMock(EntityManagerInterface::class), $this->createMock(PlatformLinkRepository::class), null, null, ['price']);
        $variant = fn (RemoteProduct $p) => $p->variants[0];

        $a = $this->remote();
        self::assertSame($priceOnly->fingerprint($a, $variant($a)), $priceOnly->fingerprint($b = $this->remote(description: 'Autre texte'), $variant($b)), 'the description is not owned');
        self::assertNotSame($priceOnly->fingerprint($a, $variant($a)), $priceOnly->fingerprint($c = $this->remote(3100), $variant($c)));

        $all = new PlatformSynchronizer($this->createMock(EntityManagerInterface::class), $this->createMock(PlatformLinkRepository::class), null, null, ['price', 'description', 'attributes']);
        self::assertNotSame($all->fingerprint($a, $variant($a)), $all->fingerprint($d = $this->remote(attributes: ['appellation' => 'Lalande-de-Pomerol']), $variant($d)));
    }

    public function testAProductGoneThereIsTakenOffSaleNotDeleted(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getFeatures')->willReturn(new ArrayCollection());
        $product->expects(self::once())->method('setAvailability')->with(ProductAvailability::DISCONTINUED);
        $product->expects(self::once())->method('setStock')->with(0);
        $link = new PlatformLink($product, 'fake', 'p1', 'v1');
        $links = $this->createMock(PlatformLinkRepository::class);
        $links->method('findProduct')->with('fake', 'p1')->willReturn([$link]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('remove');

        $synchronizer = new PlatformSynchronizer($entityManager, $links, null, null, ['stock', 'availability']);
        self::assertSame(1, $synchronizer->discontinue('fake', 'p1'));
        self::assertTrue($link->isOrphaned());
        self::assertSame(0, $synchronizer->discontinue('fake', 'p1'), 'once');
        self::assertSame(1, $synchronizer->stats()['discontinued']);
    }

    public function testAStockLevelLandsOnItsVariantByIdOrByItem(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getFeatures')->willReturn(new ArrayCollection());
        $product->expects(self::exactly(2))->method('setStock')->willReturnCallback(fn ($n) => $product);
        $link = (new PlatformLink($product, 'fake', 'p1', 'v1'))->setRemoteItem('item-7');
        $links = $this->createMock(PlatformLinkRepository::class);
        $links->method('findVariant')->willReturnCallback(fn ($g, $ref) => 'v1' === $ref ? $link : null);
        $links->method('findItem')->willReturnCallback(fn ($g, $item) => 'item-7' === $item ? $link : null);

        $synchronizer = new PlatformSynchronizer($this->createMock(EntityManagerInterface::class), $links, null, null, ['stock', 'availability']);
        self::assertTrue($synchronizer->updateStock('fake', new Stock('v1', 3)));
        self::assertTrue($synchronizer->updateStock('fake', new Stock('item-7', 0, item: 'item-7')), 'a Shopify inventory level names the item only');
        self::assertFalse($synchronizer->updateStock('fake', new Stock('v9', 1)));
        self::assertFalse((new PlatformSynchronizer($this->createMock(EntityManagerInterface::class), $links, null, null, ['price']))->updateStock('fake', new Stock('v1', 1)), 'the stock is not owned');
    }
}
