<?php

namespace Tests\Base\Marketplace\Controller;

use Base\Admin\Filter\Filter;
use Base\Admin\Filter\Filters;
use Base\Database\Repository\Finder\Group;
use Base\Marketplace\Controller\Admin\Crud\OrderCrudController;
use Base\Marketplace\Entity\Order;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * The orders' list, filtered by "cart": carts and orders share the table, and
 * every ORDER_CART* state is a cart. Said in the core's finder language, which
 * every persistence reads (Filter::matchWith(): the list read through
 * OrderRepository, omnisense/omnibase 5.x).
 */
final class OrderCartFilterTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    private function cart(): Filter
    {
        if (!method_exists(Filter::class, 'matchWith')) {
            self::markTestSkipped('Needs omnibase/admin with Filter::matchWith().');
        }
        $controller = (new \ReflectionClass(OrderCrudController::class))->newInstanceWithoutConstructor();

        return $controller->configureFilters(Filters::new())->get('cart') ?? self::fail('"cart" is a filter');
    }

    /** @return array{Order, Order} a cart and a confirmed order, stored */
    private function orders(): array
    {
        $buyer = $this->user('buyer');
        $cart = new Order($this->store());
        $cart->setCustomer($buyer);
        $cart->setRegion($this->region);
        $this->entityManager->persist($cart);
        $this->entityManager->flush();

        return [$cart, $this->paidOrder($buyer, [[$this->goods('mug'), 1]])];
    }

    public function testTheCartsAndTheOrdersThroughTheFinder(): void
    {
        $filter = $this->cart();
        self::assertTrue($filter->isNeutral(), 'every persistence can say it');
        if (!method_exists(Group::class, 'of')) {
            self::markTestSkipped('Needs omnisense/omnibase 5.x (Finder\\Group::of()).');
        }
        [$cart, $order] = $this->orders();
        $repository = $this->entityManager->getRepository(Order::class);

        foreach (['1' => [$cart, $order], '0' => [$order, $cart]] as $value => [$in, $out]) {
            $read = $repository->findBy([$filter->match((string) $value)]);
            self::assertContains($in, $read, 'finder, cart='.$value);
            self::assertNotContains($out, $read, 'finder, cart='.$value);
            self::assertSame(\count($read), $repository->count([$filter->match((string) $value)]));
        }
    }
}
