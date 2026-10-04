<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Subscription;
use Base\Marketplace\Enum\SubscriptionStatus;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Service\Credits;
use Base\Marketplace\Service\Entitlements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order is paid: each plan in it becomes an Entitlement of its buyer
 * (its grants, until its months run out - or, bought by subscription, as
 * long as the Subscription created here runs), its credits are credited,
 * and each credit pack credits its kinds, times the quantity bought.
 */
#[AsEventListener]
final class PlanPurchaseListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Entitlements $entitlements,
        private readonly Credits $credits,
    ) {
    }

    public function __invoke(OrderPaidEvent $event): void
    {
        $order = $event->order;
        $buyer = $order->getCustomer();
        if (!$buyer) {
            return;
        }
        $now = new \DateTimeImmutable();
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (!$product || (!$product->isPlan() && !$product->isCreditPack())) {
                continue;
            }
            $terms = $product->getPlanTerms();
            $quantity = max(1, (int) $item->getQuantity());
            $reference = (string) $order->getReference();
            $expiresAt = $terms->expiry($now);

            if ($product->isPlan()) {
                $subscription = $terms->isRecurring() ? $this->subscription($order, $product) : null;
                // A plan bought twice is two entitlements: their counted grants add up.
                for ($i = 0; $i < $quantity; ++$i) {
                    $this->entitlements->grant($buyer, self::code($product), $terms->grants, $expiresAt, ['product' => $product, 'order' => $order, 'subscription' => $subscription, 'level' => $terms->level]);
                }
            }
            foreach ($terms->credits as $kind => $units) {
                if ($units > 0) {
                    $this->credits->grant($buyer, (string) $kind, $units * $quantity, (string) $product, $expiresAt, $reference);
                }
            }
        }
    }

    /** A plan's code: its principal's slug for a variant (the pass, whatever its size), else its own. */
    public static function code(\Base\Marketplace\Entity\Product $product): string
    {
        $principal = $product->isVariant() && method_exists($product, 'getPrincipal') ? $product->getPrincipal() : null;

        return (string) (($principal ?? $product)->getSlug() ?: 'plan-'.$product->getId());
    }

    /** The subscription the provider opened with this order's payment, kept. */
    private function subscription(Order $order, \Base\Marketplace\Entity\Product $product): ?Subscription
    {
        foreach (array_reverse($order->getTransactions()->toArray()) as $transaction) {
            $details = $transaction->getDetails();
            if (empty($details['subscription']) || empty($details['gateway'])) {
                continue;
            }
            $existing = $this->entityManager->getRepository(Subscription::class)->findOneBy(['gateway' => $details['gateway'], 'providerReference' => $details['subscription']]);
            if ($existing) {
                return $existing;
            }
            $subscription = new Subscription($order->getCustomer(), (string) $details['gateway'], (string) $details['subscription'], $product, $order);
            $subscription->setProviderCustomer(isset($details['customer']) ? (string) $details['customer'] : null);
            $subscription->setInterval((string) $product->getPlanTerms()->interval);
            // Paid: it runs. Its period's end comes with the provider's next event (Service\Subscriptions::apply()).
            $subscription->setStatus(SubscriptionStatus::ACTIVE);
            $this->entityManager->persist($subscription);

            return $subscription;
        }

        return null;
    }
}
