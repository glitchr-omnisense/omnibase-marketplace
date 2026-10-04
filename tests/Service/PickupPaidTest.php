<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Entity\Product\OptionGroup;
use Base\Marketplace\Enum\PickupMode;
use Base\Marketplace\Enum\PickupStatus;
use Base\Marketplace\Service\Checkout;
use Base\Marketplace\Service\Pickups;
use Base\Marketplace\Service\Pricing;
use Base\Marketplace\Service\ProductOptions;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/** An order with a hand-over and options, stored, then paid: the shop has received it, at the price of its options. */
final class PickupPaidTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    public function testPaidItsHandOverIsReceivedAndFollowedByItsToken(): void
    {
        $ramen = $this->goods('ramen', 1200);
        $ramen->addOptionGroup((new OptionGroup('Suppléments', true, 0, 2))->option('Œuf mollet', 150)->option('Chashu', 300));
        $this->entityManager->flush();
        $egg = $ramen->getOptionGroups()->first()->getOptions()->first();

        $order = new Order($this->store());
        $order->setCustomer($this->user('guest'));
        $order->setRegion($this->region);
        $this->entityManager->persist($order);
        $line = (new OrderItem($ramen, 2))->applyOptions(self::getContainer()->get(ProductOptions::class)->select($ramen, [$egg->getId()]));
        $order->addItem($line);
        $this->entityManager->persist($line);
        self::getContainer()->get(Pricing::class)->reprice($order);
        $this->entityManager->flush();

        $pickups = self::getContainer()->get(Pickups::class);
        $pickup = $pickups->open($order, PickupMode::PICKUP, $pickups->now()->modify('+1 day'), ['contactName' => 'Léa', 'slot' => '12:00-12:30']);
        self::assertSame(PickupStatus::CHECKOUT, $pickup->getStatus());

        $transaction = new Transaction();
        $transaction->setTotalAmount($order->getNetPrice());
        $transaction->setCurrencyCode('EUR');
        $order->addTransaction($transaction);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();
        self::getContainer()->get(Checkout::class)->confirm($order, $transaction);

        $this->entityManager->clear();
        $stored = self::getContainer()->get(Pickups::class)->of($this->entityManager->find(Order::class, $order->getId()));
        self::assertSame(PickupStatus::RECEIVED, $stored->getStatus());
        self::assertSame('12:00-12:30', $stored->getSlot());
        $storedLine = $stored->getOrder()->getItems()->first();
        self::assertSame(1350, $storedLine->getUnitPrice(), 'the egg is in the unit price');
        self::assertSame('Œuf mollet', $storedLine->getOptionsLabel());
        self::assertSame(2700, $stored->getOrder()->getSalePrice());

        $response = self::$kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/remise/'.$stored->getToken()));
        self::assertSame(200, $response->getStatusCode(), substr(strip_tags((string) $response->getContent()), 0, 2000));
        self::assertStringContainsString((string) $stored->getOrder()->getReference(), (string) $response->getContent());
        self::assertSame(404, self::$kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/remise/'.str_repeat('a', 43)))->getStatusCode());
    }
}
