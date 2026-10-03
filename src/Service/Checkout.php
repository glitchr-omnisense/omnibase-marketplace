<?php

namespace Base\Marketplace\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Enum\OrderState;
use Base\Marketplace\Enum\PaymentState;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Event\PaymentCancelledEvent;
use Base\Marketplace\Payment\PaymentGatewayRegistry;
use Base\Marketplace\Payment\PaymentResult;
use Doctrine\DBAL\LockMode;
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
        #[Autowire('%marketplace.default_gateway%')] private readonly string $defaultGateway = 'stripe',
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

        // One payment at a time: the order's row locked, its state read from
        // the database - a double click opened two payments (two Stripe
        // sessions, two Shopify drafts) on the same cart.
        // By hand, not wrapInTransaction(): that closes the EntityManager on any
        // exception, and a buyer's double click is not a reason to break the
        // rest of the request.
        $this->entityManager->beginTransaction();
        try {
            if (null !== $order->getId()) {
                $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
                if (!str_starts_with((string) $this->stored($order, 'state'), OrderState::CART)) {
                    throw new CartException('checkout.error.pending');
                }
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
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }

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

    /**
     * The money is in: confirm, take the stock, tell the application - once.
     *
     * Confirmations come in pairs: the return page and the webhook arrive
     * together, a webhook is retried, a buyer comes back from their history,
     * an order already shipped or refunded is reported paid again. So the
     * order's row is locked and whether it is already paid is read from the
     * database, not from memory or the second-level cache: the second one
     * finds it paid and does nothing - no second delivery (two licences,
     * double hours), no stock taken twice, no refund undone.
     *
     * And delivery commits with the confirmation: OrderPaidEvent's listeners
     * run in the same transaction, so if one fails, nothing is confirmed and
     * the provider's next try delivers - rather than an order confirmed with
     * nothing delivered, which no retry would ever deliver.
     *
     * The order and the transaction are locked, not reloaded: what the caller
     * set on them before (a provider's reference) is kept, and saved either way.
     */
    public function confirm(Order $order, Transaction $transaction): void
    {
        $this->entityManager->wrapInTransaction(function () use ($order, $transaction): void {
            if (null !== $order->getId()) {
                $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            }
            if ($this->isAlreadyPaid($order, $transaction)) {
                $this->entityManager->flush();

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
            $this->entityManager->flush();
        });
    }

    /**
     * The payment did not happen (cancelled, expired, refused later): back to
     * the cart - unless the application takes it from there (the event).
     */
    public function cancel(Order $order, Transaction $transaction): PaymentCancelledEvent
    {
        // A paid order stays paid, whatever comes back late - the cancel page
        // opened from the history, an older session expiring: its transaction
        // cancelled, a refund would no longer find the card it was paid with.
        if (!$this->isAlreadyPaid($order, $transaction)) {
            $transaction->markAsCancelled();
            if (!$order->isConfirmed() && !$order->isCompleted()) {
                $order->markAsCart();
            }
            $this->entityManager->flush();
        }

        return $this->dispatcher->dispatch(new PaymentCancelledEvent($order, $transaction));
    }

    /** Whether the order or this payment is paid (or refunded) - as the database has it. */
    private function isAlreadyPaid(Order $order, Transaction $transaction): bool
    {
        if (null !== ($order->getId() ? $this->stored($order, 'paidAt') : $order->getPaidAt())) {
            return true;
        }
        $state = $transaction->getId() ? $this->stored($transaction, 'state') : null;

        return \in_array($state, [PaymentState::PAID, PaymentState::REFUND, PaymentState::REFUND_PARTIAL], true) || $transaction->isPaid();
    }

    /** A field as the database has it now - not the entity in memory, nor the second-level cache's copy. */
    private function stored(object $entity, string $field): mixed
    {
        return $this->entityManager->createQuery(sprintf('SELECT e.%s FROM %s e WHERE e.id = :id', $field, $entity::class))
            ->setParameter('id', $entity->getId())
            ->setCacheable(false)
            ->getSingleScalarResult();
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
            // A case of 6 sells by 6, from its minimum - unless a quote set the quantities.
            if (!$order->isQuoted() && $product->boundQuantity((int) $item->getQuantity()) !== (int) $item->getQuantity()) {
                throw new CartException('cart.error.minimum', ['{product}' => (string) $product, '{minimum}' => $product->getMinimumQuantity(), '{pack}' => $product->getPackSize()]);
            }
        }
    }
}
