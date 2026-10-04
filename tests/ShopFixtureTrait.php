<?php

namespace Tests\Base\Marketplace;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Sales\Region;
use Base\Marketplace\Entity\Store;

/** A store, a region, products and orders, stored: what a database test of the shop starts from. */
trait ShopFixtureTrait
{
    private ?Store $store = null;
    private ?Region $region = null;

    private function store(): Store
    {
        if (null === $this->store) {
            $this->store = new Store();
            $this->store->setTitle('Shop');
            $this->store->setSlug('shop-'.bin2hex(random_bytes(3)));
            $this->store->setCurrency('EUR');
            $this->entityManager->persist($this->store);
            $this->region = new Region();
            $this->region->setLabel('France');
            $this->region->setSlug('fr-'.bin2hex(random_bytes(3)));
            $this->region->setCurrency('EUR');
            $this->region->setCountries(['FR']);
            $this->region->setEnabled(true);
            $this->store->addRegion($this->region);
            $this->entityManager->persist($this->region);
            $this->entityManager->flush();
        }

        return $this->store;
    }

    private function goods(string $title = 'mug', int $price = 1900): Product
    {
        $product = new Product(null, $this->store(), $price, 'EUR');
        $product->setStore($this->store);
        $product->setTitle($title);
        $product->setSlug($title.'-'.bin2hex(random_bytes(3)));
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    /**
     * @param list<array{Product, int}> $lines
     * @param array<string, mixed>      $details the transaction's
     */
    private function paidOrder(\Base\Entity\User $buyer, array $lines, array $details = []): Order
    {
        $order = new Order($this->store());
        $order->setCustomer($buyer);
        $order->setRegion($this->region);
        $this->entityManager->persist($order);
        foreach ($lines as [$product, $quantity]) {
            $item = new OrderItem($product, $quantity);
            $order->addItem($item);
            $this->entityManager->persist($item);
        }
        $transaction = new Transaction();
        $transaction->setTotalAmount(1900);
        $transaction->setCurrencyCode('EUR');
        $transaction->setDetails($details);
        $order->addTransaction($transaction);
        $order->markAsPaidAt();
        $order->markAsConfirmed();
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $order;
    }
}
