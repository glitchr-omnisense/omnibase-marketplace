<?php

namespace Tests\Base\Marketplace\Attribute;

use Base\Marketplace\Attribute\OrderReference;
use Base\Marketplace\Entity\Order;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * An order's reference is drawn again when another order holds it. Its format
 * leaves only four random digits within a second: two orders made in the same
 * second drew the same one once in 9 000 pairs, and the second flush failed on
 * the unique column (SupplyTest failed so, once). Here the draw is forced to
 * collide: the random generator seeded as it was for an order already stored.
 */
final class OrderReferenceTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    public function testATakenReferenceIsDrawnAgain(): void
    {
        $buyer = $this->user('buyer');
        $mug = $this->goods('mug');

        // The same second for the stored order and the new one, or the dates differ and nothing collides.
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $second = date('yhdsmi');
            mt_srand(20261020);
            $taken = (new OrderReference())->generate();

            $first = $this->paidOrder($buyer, [[$mug, 1]]);
            (new \ReflectionProperty(Order::class, 'reference'))->setValue($first, $taken);
            $this->entityManager->flush();

            mt_srand(20261020);
            $draw = (new OrderReference())->generate();
            mt_srand(20261020);
            $order = $this->paidOrder($buyer, [[$mug, 1]]);
            if ($second === date('yhdsmi')) {
                break;
            }
        }

        self::assertSame($taken, $draw, 'the generator gave the new order the stored order\'s reference first');
        self::assertSame($taken, $first->getReference());
        self::assertNotNull($order->getReference());
        self::assertNotSame($taken, $order->getReference(), 'drawn again');
        self::assertMatchesRegularExpression('/^\d+-\d{4}-\d{12}$/', $order->getReference(), 'in the format');
    }

    public function testTwoOrdersFlushedTogetherGetTwoReferences(): void
    {
        $attribute = new class extends OrderReference {
            public function generate(): string
            {
                return 'SAME';
            }
        };
        $buyer = $this->user('buyer');
        $mug = $this->goods('mug');
        $a = $this->paidOrder($buyer, [[$mug, 1]]);
        (new \ReflectionProperty(Order::class, 'reference'))->setValue($a, null);

        $b = new Order($this->store());
        (new \ReflectionProperty(Order::class, 'reference'))->setValue($b, 'SAME');
        $this->entityManager->persist($b);

        $event = new \Doctrine\ORM\Event\OnFlushEventArgs($this->entityManager);
        $code = $attribute->unique($event, $a, 'reference');
        self::assertStringStartsWith('SAME-', $code, 'SAME is held by an order flushed with it: a suffix tells them apart');
        $this->entityManager->detach($b);
    }
}
