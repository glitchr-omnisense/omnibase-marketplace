<?php

namespace Base\Market\Service;

use Base\Entity\User;
use Base\Market\Entity\Order;
use Base\Market\Entity\Order\OrderItem;
use Base\Market\Entity\Product;
use Base\Market\Entity\Store;
use Base\Market\Enum\OrderState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The signed-in member's carts: one open Order (state CART) per store, since
 * two stores may not share a currency, a region or a way of being paid.
 *
 * The bundle's Order already is the cart - addItem()/removeItem() only work
 * while it is one - so this service only finds it, creates it, and keeps
 * the quantities sane.
 */
class Cart
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly RegionResolver $regions,
        #[Autowire('%market.cart_max_quantity%')] private readonly int $maxQuantity = 99,
    ) {
    }

    public function customer(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /** The open cart for a store, or null if the member has none (and $create is false). */
    public function of(Store $store, bool $create = false): ?Order
    {
        $customer = $this->customer();
        if (!$customer) {
            return null;
        }

        $order = $this->entityManager->getRepository(Order::class)->findOneBy([
            'customer' => $customer,
            'store' => $store,
            'state' => OrderState::CART,
        ], ['id' => 'DESC']);

        if (!$order && $create) {
            $order = new Order($store);
            $order->setCustomer($customer);
            $order->setRegion($this->regions->for($store));
            $this->entityManager->persist($order);
        }

        return $order;
    }

    /** @return Order[] every open cart of the member, one per store */
    public function all(): array
    {
        $customer = $this->customer();

        return $customer ? $this->entityManager->getRepository(Order::class)->findBy(['customer' => $customer, 'state' => OrderState::CART], ['id' => 'DESC']) : [];
    }

    /** How many items the member's carts hold, for a badge. */
    public function count(): int
    {
        $n = 0;
        foreach ($this->all() as $order) {
            foreach ($order->getItems() as $item) {
                $n += (int) $item->getQuantity();
            }
        }

        return $n;
    }

    /** @throws CartException */
    public function add(Product $product, int $quantity = 1): Order
    {
        if (!$this->customer()) {
            throw new CartException('cart.error.anonymous');
        }
        if (!$product->isForSell()) {
            throw new CartException('cart.error.not_for_sale', ['{product}' => (string) $product]);
        }
        $store = $product->getStore();
        if (!$store instanceof Store) {
            throw new CartException('cart.error.no_store', ['{product}' => (string) $product]);
        }
        if ($store->isOpen() === false) {
            throw new CartException('cart.error.store_closed', ['{store}' => (string) $store]);
        }

        $order = $this->of($store, true);
        $line = $this->lineOf($order, $product);
        $wanted = ($line ? (int) $line->getQuantity() : 0) + max(1, $quantity);
        $wanted = $this->bounded($product, $wanted);

        if ($line) {
            $line->setQuantity($wanted);
        } else {
            $line = new OrderItem($product, $wanted);
            $order->addItem($line);
            $this->entityManager->persist($line);
        }

        $this->entityManager->flush();

        return $order;
    }

    /** Set a line's quantity; zero removes it. @throws CartException */
    public function update(Order $order, OrderItem $line, int $quantity): void
    {
        $this->assertMine($order);
        if ($quantity <= 0) {
            $this->remove($order, $line);
            return;
        }
        $line->setQuantity($this->bounded($line->getProduct(), $quantity));
        $this->entityManager->flush();
    }

    /** @throws CartException */
    public function remove(Order $order, OrderItem $line): void
    {
        $this->assertMine($order);
        $order->removeItem($line);
        $this->entityManager->remove($line);
        if ($order->isEmpty()) {
            $this->entityManager->remove($order);
        }
        $this->entityManager->flush();
    }

    /** @throws CartException */
    public function assertMine(Order $order): void
    {
        if (!$order->isCustomer($this->customer()) || OrderState::CART !== $order->getState()) {
            throw new CartException('cart.error.not_yours');
        }
    }

    private function lineOf(Order $order, Product $product): ?OrderItem
    {
        foreach ($order->getItems() as $item) {
            if ($item->getProduct() === $product) {
                return $item;
            }
        }

        return null;
    }

    /** @throws CartException */
    private function bounded(?Product $product, int $quantity): int
    {
        // The product may sell fewer at once than the shop allows (one of an
        // item a member keeps forever, say): the stricter bound wins.
        $quantity = min($quantity, $this->maxQuantity, $product?->getMaxQuantity() ?? $this->maxQuantity);
        $stock = $product?->getStock();
        if (null !== $stock && $quantity > $stock) {
            if ($stock <= 0) {
                throw new CartException('cart.error.sold_out', ['{product}' => (string) $product]);
            }
            $quantity = $stock;
        }

        return max(1, $quantity);
    }
}
