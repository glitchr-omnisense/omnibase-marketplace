<?php

namespace Base\Marketplace\Service;

use Base\Entity\User;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Model\QuickPayment;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * An order in one step, with no account to open and no basket to fill:
 * products, an e-mail address, the payment.
 *
 * The address becomes the order's customer - the member it already belongs
 * to, or a new one whose password nobody knows - because an order has one;
 * nobody is signed in. Checkout::settle() takes the payment on that
 * customer's behalf, with the first payment method able to (the shop's
 * default gateway first); the provider's return and its webhook confirm it
 * (QuickOrderController, PaymentController), and OrderPaidEvent delivers as
 * for any order.
 *
 * The buyer then follows the order on a page of its own (doneUrl()): a
 * signed link, shown after the payment and good to mail - whoever holds it
 * sees the order, so what an application adds to that page
 * (QuickOrderDoneEvent: files to download, a pickup time) is for the
 * holder of the link.
 */
class QuickOrder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RegionResolver $regions,
        private readonly Pricing $pricing,
        private readonly Checkout $checkout,
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%marketplace.quick_order.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%marketplace.quick_order.link_ttl%')] private readonly int $linkTtl = 2592000,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * One order per call, for one store: the lines, priced, and its payment begun.
     *
     * @param array<array{product: Product, quantity?: int}>|Product $lines
     *
     * @throws CartException with a key of the marketplace translations (quick.error.*)
     */
    public function buy(array|Product $lines, string $email, ?User $signedIn = null): QuickPayment
    {
        if (!$this->enabled) {
            throw new CartException('quick.error.disabled');
        }
        $lines = $lines instanceof Product ? [['product' => $lines]] : $lines;
        $store = null;
        foreach ($lines as $line) {
            $product = $line['product'] ?? null;
            if (!$product instanceof Product || !$product->isForSell() || !$product->getStore() instanceof Store) {
                throw new CartException('quick.error.unavailable');
            }
            $store ??= $product->getStore();
            if ($product->getStore() !== $store) {
                throw new CartException('quick.error.one_store');
            }
        }
        if (!$store) {
            throw new CartException('quick.error.unavailable');
        }

        // Always a new order: what is paid is exactly what was asked for here,
        // whatever sits in a basket this customer may have.
        $order = new Order($store);
        $order->setCustomer($signedIn ?? $this->customer($email));
        $order->setRegion($this->regions->for($store));
        $this->entityManager->persist($order);
        foreach ($lines as $line) {
            $item = new OrderItem($line['product'], max(1, (int) ($line['quantity'] ?? 1)));
            $order->addItem($item);
            $this->entityManager->persist($item);
        }
        $this->pricing->reprice($order);
        $this->entityManager->flush();

        foreach ($this->checkout->methodsFor($order) as $method) {
            try {
                return new QuickPayment($order, $this->checkout->settle($order, $method));
            } catch (\Throwable $e) {
                // A gateway down, or refusing this order: the next one (settle() put the order back as a cart).
                $this->logger?->warning('Quick order {order}: the method "{method}" could not take the payment: {error}', ['order' => $order->getId(), 'method' => $method->getSlug(), 'error' => $e->getMessage()]);
            }
        }

        throw new CartException('quick.error.payment');
    }

    /** The page of this order for whoever holds the link: signed, valid marketplace.quick_order.link_ttl seconds. */
    public function doneUrl(Order $order): string
    {
        $url = $this->router->generate('marketplace_quick_order_done', ['reference' => $order->getReference()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateTimeImmutable(sprintf('+%d seconds', $this->linkTtl)));
    }

    /** The member for this address: the one it already belongs to, or a new one whose password nobody knows. */
    private function customer(string $email): User
    {
        $class = $this->userClass();
        $users = $this->entityManager->getRepository($class);
        $customer = $users->findOneBy(['email' => $email]);
        if ($customer instanceof User) {
            return $customer;
        }

        /** @var User $customer */
        $customer = new $class();
        $customer->setEmail($email);
        $base = preg_replace('/[^a-z0-9]+/', '', strtolower(strstr($email, '@', true) ?: 'client')) ?: 'client';
        $name = $base;
        for ($i = 2; $users->findOneBy(['username' => $name]); ++$i) {
            $name = $base.$i;
        }
        if (method_exists($customer, 'setUsername')) {
            $customer->setUsername($name);
        }
        // Never told to anyone: this member gets in by asking for a new password, like one who forgot it.
        $customer->setPlainPassword(bin2hex(random_bytes(24)));
        $customer->setRoles(['ROLE_USER']);
        $customer->verify(false);
        $this->entityManager->persist($customer);

        return $customer;
    }

    /** The application's User (App\Entity\User extends omnibase's): what an order's customer is mapped to. */
    private function userClass(): string
    {
        $class = $this->entityManager->getClassMetadata(Order::class)->getAssociationTargetClass('customer');
        // omnibase's own User is the mapped target; the application's extends it and is the one that is stored.
        if (class_exists('App\\Entity\\User') && is_a('App\\Entity\\User', $class, true)) {
            return 'App\\Entity\\User';
        }

        return $class;
    }
}
