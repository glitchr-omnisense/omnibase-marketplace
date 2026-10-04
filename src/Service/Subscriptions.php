<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Entitlement;
use Base\Marketplace\Entity\Order\Subscription;
use Base\Marketplace\Enum\SubscriptionStatus;
use Base\Marketplace\Event\EntitlementEndedEvent;
use Base\Marketplace\Event\EntitlementEndingEvent;
use Base\Marketplace\Event\SubscriptionChangedEvent;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateway;
use Base\Marketplace\Payment\PaymentGatewayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Model\Subscription as ProviderSubscription;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The subscriptions' life: what the provider tells (a renewal, a payment
 * that failed, an end) is applied to the Subscription kept here, a
 * subscriber stops theirs or opens the provider's portal, and
 * `marketplace:subscriptions` announces the rights that end - a pass run
 * out or a subscription stopped alike.
 */
class Subscriptions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?Credits $credits = null,
        #[Autowire('%marketplace.plans.reminder_days%')] private readonly int $reminderDays = 7,
    ) {
    }

    public function find(string $gateway, string $providerReference): ?Subscription
    {
        return $this->entityManager->getRepository(Subscription::class)->findOneBy(['gateway' => $gateway, 'providerReference' => $providerReference]);
    }

    /** The provider's subscription as it stands, applied to ours; null when we do not know it. */
    public function apply(string $gateway, ProviderSubscription $remote): ?Subscription
    {
        $subscription = $this->find($gateway, $remote->reference);
        if (!$subscription) {
            return null;
        }
        $previous = $subscription->getStatus();
        $periodEnd = $subscription->getCurrentPeriodEnd();
        $subscription->setStatus(SubscriptionStatus::tryFrom($remote->status) ?? SubscriptionStatus::INCOMPLETE);
        $subscription->setProviderCustomer($remote->customer ?? $subscription->getProviderCustomer());
        $subscription->setCurrentPeriodEnd($remote->currentPeriodEnd ?? $subscription->getCurrentPeriodEnd());
        $subscription->setCancelAtPeriodEnd($remote->cancelAtPeriodEnd);
        if (null !== $remote->interval) {
            $subscription->setInterval($remote->interval);
        }
        $this->entityManager->flush();
        // A new period paid: the plan's credits are credited again (the first
        // period's were, when the order was paid).
        $renewed = null !== $periodEnd && null !== $remote->currentPeriodEnd && $remote->currentPeriodEnd > $periodEnd && $subscription->isRunning();
        if ($renewed && $this->credits && $subscription->getProduct() && $subscription->getSubscriber()) {
            foreach ($subscription->getProduct()->getPlanTerms()->credits as $kind => $units) {
                if ($units > 0) {
                    $this->credits->grant($subscription->getSubscriber(), (string) $kind, $units, (string) $subscription->getProduct(), null, (string) $subscription->getOrder()?->getReference() ?: null);
                }
            }
        }
        $this->dispatcher?->dispatch(new SubscriptionChangedEvent($subscription, $previous));

        return $subscription;
    }

    /** Asks the provider where it stands and applies it. */
    public function refresh(Subscription $subscription): Subscription
    {
        $this->apply($subscription->getGateway(), $this->bridge($subscription)->gateway()->fetchSubscription($subscription->getProviderReference()));

        return $subscription;
    }

    /** Stops it: at the end of the period paid (what was paid for is kept until then), or at once. */
    public function cancel(Subscription $subscription, bool $atPeriodEnd = true): Subscription
    {
        $this->apply($subscription->getGateway(), $this->bridge($subscription)->gateway()->cancelSubscription($subscription->getProviderReference(), $atPeriodEnd));

        return $subscription;
    }

    /** The provider's page where the subscriber changes their card, reads their invoices, stops. */
    public function portalUrl(Subscription $subscription, string $returnUrl, ?string $locale = null): string
    {
        if (null === $subscription->getProviderCustomer()) {
            throw new \LogicException('This subscription has no customer at its provider yet.');
        }

        return $this->bridge($subscription)->gateway()->subscriptionPortal($subscription->getProviderCustomer(), $returnUrl, $locale);
    }

    /**
     * The rights that have ended and were not told yet: each is announced
     * once (EntitlementEndedEvent). And those that end within
     * marketplace.plans.reminder_days: announced once too (EntitlementEndingEvent).
     *
     * @return array{ended: int, ending: int}
     */
    public function sweep(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $soon = $now->modify(sprintf('+%d days', $this->reminderDays));
        $ended = $ending = 0;
        /** @var Entitlement $entitlement */
        foreach ($this->entityManager->getRepository(Entitlement::class)->findBy(['endedAt' => null]) as $entitlement) {
            if ($entitlement->getStartsAt() > $now) {
                continue;
            }
            if (!$entitlement->isActive($now)) {
                $entitlement->markEnded();
                $this->dispatcher?->dispatch(new EntitlementEndedEvent($entitlement));
                ++$ended;
                continue;
            }
            $end = $entitlement->getExpiresAt();
            $subscription = $entitlement->getSubscription();
            if (null === $end && $subscription?->isCancelAtPeriodEnd()) {
                $end = $subscription->getCurrentPeriodEnd();
            }
            if (null !== $end && $end <= $soon && null === $entitlement->getRemindedAt()) {
                $entitlement->markReminded();
                $this->dispatcher?->dispatch(new EntitlementEndingEvent($entitlement));
                ++$ending;
            }
        }
        $this->entityManager->flush();

        return ['ended' => $ended, 'ending' => $ending];
    }

    private function bridge(Subscription $subscription): OmnitradeGateway
    {
        $bridge = $this->gateways->get($subscription->getGateway());
        if (!$bridge instanceof OmnitradeGateway) {
            throw new \LogicException(sprintf('No omnitrade gateway "%s" for this subscription.', $subscription->getGateway()));
        }

        return $bridge;
    }
}
