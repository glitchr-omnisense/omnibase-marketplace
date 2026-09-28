<?php

namespace Base\Market\Service;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\OrderItem;
use Base\Market\Entity\Sales\Discount;
use Base\Market\Entity\Sales\Discount\Coupon;
use Base\Market\Entity\Sales\Discount\Promotion;
use Base\Market\Entity\Sales\Tax\Vat;
use Doctrine\ORM\EntityManagerInterface;

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
 */
final class Pricing
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
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

        // VAT on each line's sale price (after its discounts): the first VAT
        // whose scopes hold the product or the order's region. The order's
        // net price adds it (Order::getVatCharge()); it was never set.
        $vats = $this->entityManager->getRepository(Vat::class)->findAll();
        foreach ($order->getItems() as $item) {
            $rate = $this->vatRate($vats, $item->getProduct(), $order->getRegion());
            $item->setVatCharge((int) round($item->getSalePrice() * $rate));
        }
    }

    /** @param Vat[] $vats */
    private function vatRate(array $vats, ?object $product, ?object $region): float
    {
        foreach ($vats as $vat) {
            if (!$vat->getRate()) {
                continue;
            }
            foreach ($vat->getScopes() as $scope) {
                try {
                    if (($product && $scope->contains($product)) || ($region && $scope->contains($region))) {
                        return (float) $vat->getRate();
                    }
                } catch (\Throwable) {
                    // A scope that cannot judge this subject does not hold it.
                }
            }
        }

        return 0.0;
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
