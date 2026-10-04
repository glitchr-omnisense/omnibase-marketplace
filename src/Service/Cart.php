<?php

namespace Base\Marketplace\Service;

use Base\Entity\User;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Enum\OrderState;
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
        #[Autowire('%marketplace.cart_max_quantity%')] private readonly int $maxQuantity = 99,
        private readonly ?ProductOptions $options = null,
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

    /**
     * @param iterable<int|string> $options the options chosen (Product\OptionGroup), by id: a line per product and choice
     *
     * @throws CartException
     */
    public function add(Product $product, int $quantity = 1, iterable $options = []): Order
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

        // Checked before a cart is made: a cooking missing, an extra too many.
        $selection = ($this->options ?? new ProductOptions())->select($product, $options);

        $order = $this->of($store, true);
        $line = $this->lineOf($order, $product, $selection->key());
        // Adding one case of 6 adds 6: a quantity below a lot means one lot.
        $wanted = ($line ? (int) $line->getQuantity() : 0) + max($product->getPackSize(), $quantity);
        $wanted = $this->bounded($product, $wanted);

        if ($line) {
            $line->setQuantity($wanted);
        } else {
            $line = new OrderItem($product, $wanted);
            if (!$selection->isEmpty()) {
                $line->applyOptions($selection);
            }
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

    private function lineOf(Order $order, Product $product, string $optionsKey = ''): ?OrderItem
    {
        foreach ($order->getItems() as $item) {
            if ($item->getProduct() === $product && $item->getOptionsKey() === $optionsKey) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The quantity a line may hold: the product's lots (a case of 6: 6, 12,
     * 18...) and its minimum, under the shop's cart_max_quantity, the
     * product's own maximum and its stock.
     *
     * @throws CartException
     */
    private function bounded(?Product $product, int $quantity): int
    {
        // The product may sell fewer at once than the shop allows (one of an
        // item a member keeps forever, say): the stricter bound wins.
        $max = min($this->maxQuantity, $product?->getMaxQuantity() ?? $this->maxQuantity);
        $stock = $product?->getStock();
        if (null !== $stock && $stock <= 0) {
            throw new CartException('cart.error.sold_out', ['{product}' => (string) $product]);
        }
        if (null !== $stock) {
            $max = min($max, $stock);
        }
        if (!$product) {
            return max(1, min($quantity, $max));
        }

        $bounded = $product->boundQuantity($quantity, $max);
        if (0 === $bounded) {
            // Not even one lot, or the minimum, fits: what is left, or what
            // the shop lets one order hold, is less than a case.
            throw new CartException(null !== $stock && $stock < $product->getMinimumQuantity() ? 'cart.error.sold_out' : 'cart.error.minimum', [
                '{product}' => (string) $product,
                '{minimum}' => $product->getMinimumQuantity(),
                '{pack}' => $product->getPackSize(),
            ]);
        }

        return $bounded;
    }
}
