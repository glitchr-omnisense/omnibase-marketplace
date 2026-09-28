<?php

namespace Base\Market\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Order\Transaction;
use Base\Market\Event\OrderPaidEvent;
use Base\Market\Payment\PaymentGatewayRegistry;
use Base\Market\Payment\PaymentResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * From a cart to an order: check it can still be bought, open a
 * transaction, hand it to the payment method's gateway, and act on the
 * answer.
 *
 *   paid      the order is confirmed, stock goes down, OrderPaidEvent is
 *             dispatched (an application delivers what does not ship there);
 *   pending   the order waits for its money - see ManualGateway;
 *   redirect  the buyer goes to pay elsewhere; the gateway's return route
 *             calls confirm() when it comes back paid;
 *   refused   the cart stays a cart, the transaction is cancelled.
 */
class Checkout
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Cart $cart,
        #[Autowire('%market.default_gateway%')] private readonly string $defaultGateway = 'stripe',
    ) {
    }

    /** @return PaymentMethod[] the methods able to take this order */
    public function methodsFor(Order $order): array
    {
        $methods = $this->gateways->usableFor($order, $this->entityManager->getRepository(PaymentMethod::class)->findAll());
        // The shop's default gateway first: checkout preselects the first method.
        usort($methods, fn (PaymentMethod $a, PaymentMethod $b) => ($b->getGatewayFactory() === $this->defaultGateway) <=> ($a->getGatewayFactory() === $this->defaultGateway));

        return $methods;
    }

    /** @throws CartException */
    public function pay(Order $order, PaymentMethod $method): PaymentResult
    {
        $this->cart->assertMine($order);

        return $this->settle($order, $method);
    }

    /**
     * pay() without asking who is logged in: for a payment the application
     * makes on the customer's behalf, where nobody is - a purchase that a
     * webhook completes, say. The caller vouches that the order is the
     * customer's own and still a cart.
     *
     * @throws CartException
     */
    public function settle(Order $order, PaymentMethod $method): PaymentResult
    {
        $this->assertBuyable($order);

        $gateway = $this->gateways->for($method);
        if (!$gateway || !$gateway->supports($order, $method)) {
            throw new CartException('checkout.error.method');
        }

        $customer = $order->getCustomer();
        $transaction = new Transaction();
        $transaction->setTotalAmount($order->getNetPrice());
        $transaction->setCurrencyCode($order->getCurrency());
        $transaction->setDescription((string) $order->getStore());
        $transaction->setClientId($customer ? (string) $customer->getId() : null);
        $transaction->setClientEmail($customer?->getEmail());

        $order->setPaymentMethod($method);
        $order->addTransaction($transaction);
        $order->markAsPending();
        $this->entityManager->persist($transaction);
        $this->entityManager->flush(); // the order gets its reference here

        $transaction->setNumber($order->getReference());
        try {
            $result = $gateway->pay($order, $transaction, $method);
        } catch (\Throwable $e) {
            // A gateway that breaks must not leave the order pending with
            // nobody's money on it: back to the cart, then let it surface.
            $transaction->markAsCancelled();
            $order->markAsCart();
            $this->entityManager->flush();

            throw $e;
        }

        if ($result->isPaid()) {
            $this->confirm($order, $transaction);
        } elseif ($result->isRefused()) {
            $transaction->markAsCancelled();
            $order->markAsCart();
            $this->entityManager->flush();
        } else {
            $this->entityManager->flush();
        }

        return $result;
    }

    /** The money is in: confirm, take the stock, tell the application. */
    public function confirm(Order $order, Transaction $transaction): void
    {
        if ($order->isConfirmed() || $order->isCompleted()) {
            return;
        }

        $transaction->markAsPaid();
        $order->markAsPaidAt();
        $order->markAsConfirmed();
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if ($product && null !== $product->getStock()) {
                $product->setStock(max(0, $product->getStock() - (int) $item->getQuantity()));
            }
        }
        $this->entityManager->flush();

        $this->dispatcher->dispatch(new OrderPaidEvent($order));
    }

    /** The payment did not happen (cancelled, expired, refused later): back to the cart. */
    public function cancel(Order $order, Transaction $transaction): void
    {
        $transaction->markAsCancelled();
        if (!$order->isConfirmed() && !$order->isCompleted()) {
            $order->markAsCart();
        }
        $this->entityManager->flush();
    }

    /** @throws CartException */
    private function assertBuyable(Order $order): void
    {
        if ($order->isEmpty()) {
            throw new CartException('checkout.error.empty');
        }
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (!$product || !$product->isForSell()) {
                throw new CartException('cart.error.not_for_sale', ['{product}' => (string) ($product ?? $item)]);
            }
            if (null !== $product->getStock() && $product->getStock() < (int) $item->getQuantity()) {
                throw new CartException('cart.error.sold_out', ['{product}' => (string) $product]);
            }
        }
    }
}
