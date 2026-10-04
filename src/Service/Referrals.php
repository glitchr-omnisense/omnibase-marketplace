<?php

namespace Base\Marketplace\Service;

use Base\Entity\Layout\Attribute\Adapter\Common\AbstractActionAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Entity\User;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Entity\Sales\Attribute\DiscountAction;
use Base\Marketplace\Entity\Sales\Attribute\DiscountScope;
use Base\Marketplace\Entity\Sales\Discount\Coupon;
use Base\Marketplace\Entity\Sales\Referral\Referral;
use Base\Marketplace\Entity\Sales\Referral\ReferralCode;
use Base\Marketplace\Entity\Sales\Referral\Reward;
use Base\Marketplace\Enum\OrderState;
use Base\Marketplace\Enum\ReferralStatus;
use Base\Marketplace\Event\ReferralQualifyingEvent;
use Base\Marketplace\Event\ReferralRewardedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Referrals, for any shop: each member has a code; somebody who signs up
 * through it is their referee and gets a welcome coupon; once the referee's
 * first order is paid and has held for the cooling-off period, the referrer
 * is rewarded - a coupon, or credits.
 *
 * Against abuse: no referral of oneself (the same account, the same e-mail
 * address once its "+tag" and a Gmail's dots are taken off, the same card
 * when the provider's fingerprint is known), a ceiling of rewards per month,
 * a refunded order qualifies nothing, and any referral may be rejected in
 * review before its reward leaves.
 *
 * The coupons are made on the shop's own discount adapters (a scope, an
 * action, by their codes): without them in the database, no coupon.
 *
 * @phpstan-type RewardConfig array{type: ?string, scope: ?string, action: ?string, value: float|int|null, validity: string, prefix: string, label: string, credit_kind: ?string, credit_quantity: int}
 */
class Referrals
{
    /**
     * @param RewardConfig $referee
     * @param RewardConfig $referrer
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Credits $credits,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        #[Autowire('%marketplace.referral.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%marketplace.referral.cooling_days%')] private readonly int $coolingDays = 14,
        #[Autowire('%marketplace.referral.monthly_cap%')] private readonly int $monthlyCap = 10,
        #[Autowire('%marketplace.referral.referee%')] private readonly array $referee = [],
        #[Autowire('%marketplace.referral.referrer%')] private readonly array $referrer = [],
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** A member's code, made the first time it is asked. */
    public function codeFor(User $member): ReferralCode
    {
        $codes = $this->entityManager->getRepository(ReferralCode::class);
        if ($code = $codes->findOneBy(['owner' => $member])) {
            return $code;
        }
        do {
            $code = new ReferralCode($member);
        } while ($codes->findOneBy(['code' => $code->getCode()]));
        $this->entityManager->persist($code);
        $this->entityManager->flush();

        return $code;
    }

    public function find(string $code): ?ReferralCode
    {
        $found = $this->entityManager->getRepository(ReferralCode::class)->findOneBy(['code' => ReferralCode::normalize($code)]);

        return $found?->isEnabled() ? $found : null;
    }

    public function referralOf(User $referee): ?Referral
    {
        return $this->entityManager->getRepository(Referral::class)->findOneBy(['referee' => $referee]);
    }

    /**
     * A new member came through a code: they are its owner's referee, and
     * get their welcome (a coupon, when configured).
     *
     * @throws ReferralException disabled, unknown, self, email, already, customer
     */
    public function attribute(string $code, User $referee): Referral
    {
        if (!$this->enabled) {
            throw new ReferralException('disabled');
        }
        $referralCode = $this->find($code) ?? throw new ReferralException('unknown');
        $referrer = $referralCode->getOwner();
        if ($this->same($referrer, $referee)) {
            throw new ReferralException('self');
        }
        if (self::canonicalEmail((string) $referrer?->getEmail()) === self::canonicalEmail((string) $referee->getEmail())) {
            throw new ReferralException('email');
        }
        if ($this->referralOf($referee)) {
            throw new ReferralException('already');
        }
        if ($this->paidOrders($referee) > 0) {
            throw new ReferralException('customer'); // a referral brings somebody new
        }

        $referral = new Referral($referralCode, $referee);
        $this->entityManager->persist($referral);
        $this->entityManager->flush();
        $this->reward($referral, $referee, Reward::REFEREE, $this->referee);

        return $referral;
    }

    /**
     * An order is paid: when it is the first of a referee whose referral
     * waits, the referral qualifies - or is refused.
     */
    public function onOrderPaid(Order $order): ?Referral
    {
        $buyer = $order->getCustomer();
        $referral = $buyer ? $this->referralOf($buyer) : null;
        if (!$this->enabled || !$referral || ReferralStatus::PENDING !== $referral->getStatus()) {
            return $referral;
        }
        $event = new ReferralQualifyingEvent($referral, $order, $this->fingerprint($order));
        $this->dispatcher?->dispatch($event);
        $reference = (string) $order->getReference();
        if (null !== $event->rejection) {
            $referral->qualify($reference, $event->fingerprint)->reject($event->rejection);
        } elseif (null !== $event->fingerprint && $this->paidWith($referral->getReferrer(), $event->fingerprint)) {
            $referral->qualify($reference, $event->fingerprint)->reject('card');
        } else {
            $referral->qualify($reference, $event->fingerprint);
        }
        $this->entityManager->flush();

        return $referral;
    }

    /**
     * The referrals whose order has held for the cooling-off period: their
     * referrers are rewarded - unless the order was refunded meanwhile, or
     * the referrer reached the month's ceiling.
     *
     * @return array{rewarded: int, rejected: int}
     */
    public function release(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $before = $now->modify(sprintf('-%d days', $this->coolingDays));
        $rewarded = $rejected = 0;
        /** @var Referral $referral */
        foreach ($this->entityManager->getRepository(Referral::class)->findBy(['status' => ReferralStatus::QUALIFIED], ['qualifiedAt' => 'ASC']) as $referral) {
            if ($referral->getQualifiedAt() > $before) {
                continue;
            }
            $referrer = $referral->getReferrer();
            $order = $this->entityManager->getRepository(Order::class)->findOneBy(['reference' => $referral->getOrderReference()]);
            if (!$referrer || ($order && \in_array($order->getState(), [OrderState::REFUND, OrderState::CANCEL], true))) {
                $referral->reject('refunded');
                ++$rejected;
                continue;
            }
            if ($this->rewardedIn($referrer, $now) >= $this->monthlyCap) {
                $referral->reject('cap');
                ++$rejected;
                continue;
            }
            $this->reward($referral, $referrer, Reward::REFERRER, $this->referrer);
            $referral->markRewarded();
            $this->entityManager->flush();
            ++$rewarded;
        }
        $this->entityManager->flush();

        return ['rewarded' => $rewarded, 'rejected' => $rejected];
    }

    /** In review, before its reward leaves. */
    public function reject(Referral $referral, string $reason = 'review'): void
    {
        if (ReferralStatus::REWARDED !== $referral->getStatus()) {
            $referral->reject($reason);
            $this->entityManager->flush();
        }
    }

    /** @return list<Reward> */
    public function rewardsOf(User $beneficiary): array
    {
        return $this->entityManager->getRepository(Reward::class)->findBy(['beneficiary' => $beneficiary], ['id' => 'DESC']);
    }

    /** @return list<Referral> those who came through this member's code */
    public function referralsBy(User $referrer): array
    {
        $code = $this->entityManager->getRepository(ReferralCode::class)->findOneBy(['owner' => $referrer]);

        return $code ? $this->entityManager->getRepository(Referral::class)->findBy(['code' => $code], ['id' => 'DESC']) : [];
    }

    /** "Lea.Martin+promo@GoogleMail.com" and "leamartin@gmail.com" are one person. */
    public static function canonicalEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];
        if (\in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    /** @param RewardConfig $config */
    private function reward(Referral $referral, User $beneficiary, string $role, array $config): ?Reward
    {
        $type = $config['type'] ?? null;
        if (null === $type) {
            return null;
        }
        $reward = new Reward($referral, $beneficiary, $role);
        if ('credit' === $type) {
            $kind = (string) ($config['credit_kind'] ?? '');
            $quantity = (int) ($config['credit_quantity'] ?? 0);
            if ('' === $kind || $quantity <= 0) {
                return null;
            }
            $this->credits->grant($beneficiary, $kind, $quantity, 'referral');
            $reward->setCredit($kind, $quantity);
        } else {
            $coupon = $this->coupon($beneficiary, $config);
            if (!$coupon) {
                return null; // the shop's discount adapters are not seeded: nothing to hand out
            }
            $reward->setCoupon($coupon);
        }
        $this->entityManager->persist($reward);
        $this->entityManager->flush();
        $this->dispatcher?->dispatch(new ReferralRewardedEvent($reward));

        return $reward;
    }

    /**
     * A coupon of the beneficiary's own, used once.
     *
     * @param RewardConfig $config
     */
    private function coupon(User $owner, array $config): ?Coupon
    {
        $scopeAdapter = $this->entityManager->getRepository(AbstractScopeAdapter::class)->findOneBy(['code' => $config['scope'] ?? 'scope-user']);
        $actionAdapter = $this->entityManager->getRepository(AbstractActionAdapter::class)->findOneBy(['code' => $config['action'] ?? '']);
        if (!$scopeAdapter || !$actionAdapter) {
            return null;
        }
        $coupon = new Coupon();
        $coupon->setLabel((string) ($config['label'] ?? 'Referral'));
        $coupon->setCode(substr((string) ($config['prefix'] ?? 'REF'), 0, 6).'-'.strtoupper(bin2hex(random_bytes(4))));
        $coupon->setValidAt(new \DateTime());
        $coupon->setExpiredAt(new \DateTime((string) ($config['validity'] ?? '+3 months')));
        $scope = new DiscountScope($scopeAdapter);
        $scope->setValue($owner);
        $coupon->addScope($scope);
        $action = new DiscountAction($actionAdapter);
        $action->setValue($config['value'] ?? 0);
        $coupon->addAction($action);
        $coupon->setQuota(1);
        $coupon->setQuotaPerCustomer(1);
        $coupon->setOwner($owner);
        $this->entityManager->persist($coupon);

        return $coupon;
    }

    private function same(?User $a, ?User $b): bool
    {
        return null !== $a && null !== $b && ($a === $b || (null !== $a->getId() && $a->getId() === $b->getId()));
    }

    private function paidOrders(User $customer): int
    {
        if (null === $customer->getId()) {
            return 0;
        }

        return (int) $this->entityManager->createQuery('SELECT COUNT(o.id) FROM '.Order::class.' o WHERE o.customer = :c AND o.paidAt IS NOT NULL')
            ->setParameter('c', $customer)->getSingleScalarResult();
    }

    /** What the order was paid with, when its transaction's details say (a "fingerprint" key). */
    private function fingerprint(Order $order): ?string
    {
        foreach ($order->getTransactions() as $transaction) {
            $fingerprint = $transaction->getDetails()['fingerprint'] ?? null;
            if (\is_string($fingerprint) && '' !== $fingerprint) {
                return $fingerprint;
            }
        }

        return null;
    }

    /** Whether that member ever paid with that same means. */
    private function paidWith(?User $member, string $fingerprint): bool
    {
        if (null === $member?->getId()) {
            return false;
        }
        $transactions = $this->entityManager->createQuery('SELECT t FROM '.Transaction::class.' t JOIN t.order o WHERE o.customer = :c')
            ->setParameter('c', $member)->getResult();
        foreach ($transactions as $transaction) {
            if (($transaction->getDetails()['fingerprint'] ?? null) === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    private function rewardedIn(User $referrer, \DateTimeImmutable $month): int
    {
        $from = $month->modify('first day of this month')->setTime(0, 0);

        return (int) $this->entityManager->createQuery('SELECT COUNT(r.id) FROM '.Reward::class.' r WHERE r.beneficiary = :b AND r.role = :role AND r.grantedAt >= :from')
            ->setParameter('b', $referrer)->setParameter('role', Reward::REFERRER)->setParameter('from', $from)->getSingleScalarResult();
    }
}
