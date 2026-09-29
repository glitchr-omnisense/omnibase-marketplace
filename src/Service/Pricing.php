<?php

namespace Base\Market\Service;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\OrderItem;
use Base\Market\Entity\Sales\Discount;
use Base\Market\Entity\Sales\Discount\Coupon;
use Base\Market\Entity\Sales\Discount\Promotion;
use Base\Market\Entity\Product;
use Base\Market\Entity\Sales\Attribute\Scope\ProductAdapter;
use Base\Market\Entity\Sales\Attribute\Scope\RegionAdapter;
use Base\Market\Entity\Sales\Attribute\Scope\StoreAdapter;
use Base\Market\Entity\Sales\Attribute\Scope\TaxonAdapter;
use Base\Market\Entity\Sales\Region;
use Base\Market\Entity\Sales\Tax\Vat;
use Base\Market\Pricing\VatExemptionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Prices a cart: the promotions running now, then the coupons the member
 * entered, through the discounts' own rules, scopes and actions.
 *
 *   rules    must all hold for the order (a minimum total, a product in
 *            the cart, a quantity...) - compliesWith()
 *   scopes   which lines a line-level action touches (a store, a product,
 *            a taxon...) - contains(); no scope means every line
 *   actions  what comes off (a percentage, a fixed amount) - apply(); an
 *            action applying to items cuts each line in scope, otherwise
 *            it cuts the order
 *
 * A coupon for "individual use" stands alone: no promotion, no other
 * coupon. Nothing is ever cut below zero. Paid orders are never repriced.
 *
 * Prices are stored before VAT; each line's VAT is the most specific rate
 * whose scopes hold its product - see vatRateFor() - unless the order is
 * exempt (VatExemptionInterface: a reverse charge, an export).
 */
final class Pricing implements ResetInterface
{
    /**
     * How specific a VAT scope is: a product's own rate before its taxon's,
     * its store's, then its region's - so a grocery store's reduced rate wins
     * over the country's standard one. A scope without adapter holds
     * everything and ranks last.
     */
    private const SPECIFICITY = [
        ProductAdapter::class => 4,
        TaxonAdapter::class => 3,
        StoreAdapter::class => 2,
        RegionAdapter::class => 1,
    ];

    /** @var Vat[]|null the rates, read once a request */
    private ?array $vats = null;

    /** @param iterable<VatExemptionInterface> $exemptions */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[AutowireIterator('market.vat_exemption')] private readonly iterable $exemptions = [],
    ) {
    }

    public function reset(): void
    {
        $this->vats = null;
    }

    public function reprice(Order $order): void
    {
        if ($order->isPaid()) {
            return;
        }

        foreach ($order->getItems() as $item) {
            $item->setDiscountCharge(0);
        }
        $order->setDiscountCharge(0);

        $now = new \DateTimeImmutable();
        $coupons = array_values(array_filter($order->getCoupons(), fn (Coupon $coupon) => $this->isRunning($coupon, $now)));
        $alone = array_values(array_filter($coupons, fn (Coupon $coupon) => $coupon->isIndividualUse()));
        $discounts = $alone ? [$alone[0]] : array_merge($this->promotions($now), $coupons);

        $orderCut = 0;
        foreach ($discounts as $discount) {
            if (!$this->holds($discount, $order)) {
                continue;
            }
            foreach ($discount->getActions() as $action) {
                $adapter = $action->getAdapter();
                if (!$adapter) {
                    continue;
                }
                try {
                    if (method_exists($adapter, 'appliesToItems') && $adapter->appliesToItems()) {
                        foreach ($order->getItems() as $item) {
                            if ($this->inScope($discount, $item)) {
                                $cut = max(0, (int) round((float) $adapter->apply($action->getValue(), $item)));
                                $item->setDiscountCharge(min($item->getGrossPrice(), $item->getDiscountCharge() + $cut));
                            }
                        }
                    } else {
                        $orderCut += max(0, (int) round((float) $adapter->apply($action->getValue(), $order)));
                    }
                } catch (\Throwable) {
                    // A misconfigured action takes nothing off rather than breaking the cart.
                }
            }
        }

        $order->setDiscountCharge(min($orderCut, $order->getSalePrice()));

        // VAT on each line's sale price (after its discounts), none for an
        // exempt order. The order's net price adds it (Order::getVatCharge()).
        $order->setVatExemption($this->exemption($order));
        foreach ($order->getItems() as $item) {
            $rate = $order->isVatExempt() ? 0.0 : $this->vatRate($item->getProduct(), $order->getRegion());
            $item->setVatCharge((int) round($item->getSalePrice() * $rate));
        }
    }

    /** The first exemption's mention for this order, or null: it pays VAT. */
    private function exemption(Order $order): ?string
    {
        foreach ($this->exemptions as $exemption) {
            if (null !== $mention = $exemption->exempts($order)) {
                return $mention;
            }
        }

        return null;
    }

    /**
     * The VAT rate a product sells at (0.055 for 5.5 %): the most specific
     * VAT whose scopes hold it, else the one of the region given (or the
     * order's). 0 when none does, and in an order exempt from VAT.
     */
    public function vatRateFor(Product $product, ?Region $region = null, ?Order $order = null): float
    {
        return $order?->isVatExempt() ? 0.0 : $this->vatRate($product, $region ?? $order?->getRegion());
    }

    /**
     * A product's unit price with its VAT, in cents: what a buyer is shown.
     * Without an order, the VAT is the product's own (an anonymous visitor
     * sees prices VAT included); in an exempt order, none.
     */
    public function priceWithVat(Product $product, ?Region $region = null, ?Order $order = null): int
    {
        return (int) round((int) $product->getUnitPrice() * (1 + $this->vatRateFor($product, $region, $order)));
    }

    private function vatRate(?object $product, ?object $region): float
    {
        $this->vats ??= $this->entityManager->getRepository(Vat::class)->findAll();

        $best = null;
        $bestRank = -1;
        foreach ($this->vats as $vat) {
            if (!$vat->getRate()) {
                continue;
            }
            foreach ($vat->getScopes() as $scope) {
                $adapter = $scope->getAdapter();
                $rank = 0;
                foreach (self::SPECIFICITY as $class => $specificity) {
                    if ($adapter instanceof $class) {
                        $rank = $specificity;
                        break;
                    }
                }
                if ($rank <= $bestRank) {
                    continue;
                }

                try {
                    // The region only answers for a region's VAT: a store's
                    // scope also "holds" any region the store sells in, which
                    // would hand its rate to every other store there.
                    $holds = ($product && $scope->contains($product))
                        || ($region && $adapter instanceof RegionAdapter && $scope->contains($region));
                } catch (\Throwable) {
                    // A scope that cannot judge this subject does not hold it.
                    $holds = false;
                }
                if ($holds) {
                    $best = (float) $vat->getRate();
                    $bestRank = $rank;
                }
            }
        }

        return $best ?? 0.0;
    }

    /**
     * Add a coupon to a cart.
     *
     * @throws CartException coupon.error.*
     */
    public function applyCoupon(Order $order, string $code, ?object $customer): Coupon
    {
        $code = trim($code);
        $coupon = '' === $code ? null : $this->entityManager->getRepository(Coupon::class)->findOneBy(['code' => $code]);
        if (!$coupon instanceof Coupon) {
            throw new CartException('coupon.error.unknown', ['{code}' => $code]);
        }
        if (in_array($coupon, $order->getCoupons(), true)) {
            throw new CartException('coupon.error.already', ['{code}' => $code]);
        }
        if ($coupon->getOwner() && $coupon->getOwner()->getId() !== $customer?->getId()) {
            throw new CartException('coupon.error.not_yours', ['{code}' => $code]);
        }
        if (!$this->isRunning($coupon, new \DateTimeImmutable())) {
            throw new CartException('coupon.error.expired', ['{code}' => $code]);
        }
        $paid = array_filter($coupon->getOrders()->toArray(), fn (Order $o) => $o->isPaid());
        if (null !== $coupon->getQuota() && count($paid) >= $coupon->getQuota()) {
            throw new CartException('coupon.error.quota', ['{code}' => $code]);
        }
        if (null !== $coupon->getQuotaPerCustomer() && $customer
            && count(array_filter($paid, fn (Order $o) => $o->isCustomer($customer))) >= $coupon->getQuotaPerCustomer()) {
            throw new CartException('coupon.error.quota', ['{code}' => $code]);
        }
        if (!$this->holds($coupon, $order)) {
            throw new CartException('coupon.error.conditions', ['{code}' => $code]);
        }

        $order->addDiscount($coupon);
        $coupon->addOrder($order);
        $this->reprice($order);

        return $coupon;
    }

    public function removeCoupon(Order $order, Coupon $coupon): void
    {
        $order->removeDiscount($coupon);
        $this->reprice($order);
    }

    /** @return Promotion[] running now, highest priority first */
    private function promotions(\DateTimeImmutable $now): array
    {
        $promotions = $this->entityManager->getRepository(Promotion::class)->findBy([], ['priority' => 'DESC']);

        return array_values(array_filter($promotions, fn (Promotion $promotion) => $this->isRunning($promotion, $now)));
    }

    private function isRunning(Discount $discount, \DateTimeImmutable $now): bool
    {
        return (null === $discount->getValidAt() || $discount->getValidAt() <= $now)
            && (null === $discount->getExpiredAt() || $discount->getExpiredAt() > $now);
    }

    private function holds(Discount $discount, Order $order): bool
    {
        foreach ($discount->getRules() as $rule) {
            try {
                if (!$rule->getAdapter() || !$rule->getAdapter()->compliesWith($rule->getValue(), $order)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    private function inScope(Discount $discount, OrderItem $item): bool
    {
        if ($discount->getScopes()->isEmpty()) {
            return true;
        }
        foreach ($discount->getScopes() as $scope) {
            try {
                if ($scope->getAdapter()?->contains($scope->getValue(), $item)) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }
}
