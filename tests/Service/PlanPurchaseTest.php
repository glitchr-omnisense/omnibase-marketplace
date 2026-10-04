<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Entitlement;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Subscription;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\ProductKind;
use Base\Marketplace\Enum\SubscriptionStatus;
use Base\Marketplace\Event\EntitlementEndedEvent;
use Base\Marketplace\Event\EntitlementEndingEvent;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\EventListener\PlanPurchaseListener;
use Base\Marketplace\Model\PlanTerms;
use Base\Marketplace\Payment\PaymentGatewayRegistry;
use Base\Marketplace\Service\Credits;
use Base\Marketplace\Service\Entitlements;
use Base\Marketplace\Service\Subscriptions;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/** A plan or a credit pack paid: the rights and the credits of its buyer; then their end. */
final class PlanPurchaseTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    private function product(ProductKind $kind, PlanTerms $terms, string $slug): Product
    {
        $product = $this->goods($slug);
        $product->setKind($kind);
        $product->setPlan($terms);
        $this->entityManager->flush();

        return $product;
    }

    /** @param list<array{Product, int}> $lines */
    private function order(\Base\Entity\User $buyer, array $lines, array $details = []): Order
    {
        return $this->paidOrder($buyer, $lines, $details);
    }

    public function testAPassGrantsItsRightsAndItsCredits(): void
    {
        $rights = new Entitlements($this->entityManager);
        $credits = new Credits($this->entityManager);
        $host = $this->user('host');
        $pass = $this->product(ProductKind::PLAN, new PlanTerms(months: 12, grants: ['events.major' => 1, 'guests' => 150, 'list' => true], credits: ['stationery' => 2000], level: 2), 'plan-c');
        $pack = $this->product(ProductKind::CREDIT_PACK, new PlanTerms(credits: ['ai' => 100]), 'pack-ai');
        $mug = $this->product(ProductKind::GOODS, new PlanTerms(), 'mug');

        (new PlanPurchaseListener($this->entityManager, $rights, $credits))(new OrderPaidEvent($this->order($host, [[$pass, 1], [$pack, 3], [$mug, 2]])));

        $held = $rights->active($host);
        self::assertCount(1, $held);
        self::assertStringStartsWith('plan-c-', $held[0]->getCode());
        self::assertNotEmpty($held[0]->getOrderReference());
        self::assertSame(2, $held[0]->getLevel());
        self::assertEqualsWithDelta((new \DateTimeImmutable('+12 months'))->getTimestamp(), $held[0]->getExpiresAt()->getTimestamp(), 5);
        self::assertSame(150, $rights->limit($host, 'guests'));
        self::assertSame(1, $rights->remaining($host, 'events.major'));
        self::assertSame(2000, $credits->balance($host, 'stationery'));
        self::assertSame(300, $credits->balance($host, 'ai'), 'three packs of 100');
        self::assertSame(0, $credits->balance($host, 'stationery', new \DateTimeImmutable('+13 months')), 'the plan\'s credits end with it');
        self::assertSame(300, $credits->balance($host, 'ai', new \DateTimeImmutable('+13 months')), 'a pack\'s do not');
    }

    public function testASubscriptionCarriesItsRightsAsLongAsItRuns(): void
    {
        $rights = new Entitlements($this->entityManager);
        $host = $this->user('host');
        $plan = $this->product(ProductKind::PLAN, new PlanTerms(PlanTerms::RECURRING, 'month', grants: ['ai' => true], level: 4), 'plan-d');
        $reference = 'sub_'.bin2hex(random_bytes(4));

        (new PlanPurchaseListener($this->entityManager, $rights, new Credits($this->entityManager)))(new OrderPaidEvent($this->order($host, [[$plan, 1]], ['gateway' => 'stripe', 'subscription' => $reference, 'customer' => 'cus_1'])));
        $this->entityManager->flush();

        $held = $rights->active($host);
        self::assertCount(1, $held);
        self::assertNull($held[0]->getExpiresAt());
        $subscription = $held[0]->getSubscription();
        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertSame($reference, $subscription->getProviderReference());
        self::assertSame('cus_1', $subscription->getProviderCustomer());
        self::assertSame('month', $subscription->getInterval());
        self::assertTrue($rights->allows($host, 'ai'));

        // It ends: the right goes with it, and its end is announced once.
        $subscription->setStatus(SubscriptionStatus::CANCELLED);
        $this->entityManager->flush();
        self::assertFalse($rights->allows($host, 'ai'));

        $ended = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EntitlementEndedEvent::class, static function (EntitlementEndedEvent $e) use (&$ended): void { $ended[] = $e->entitlement->getCode(); });
        $subscriptions = new Subscriptions($this->entityManager, new PaymentGatewayRegistry([]), $dispatcher);
        $subscriptions->sweep();
        $subscriptions->sweep();
        self::assertSame(1, \count(array_keys($ended, $held[0]->getCode(), true)), 'told once');
    }

    public function testARightAboutToEndIsAnnouncedOnce(): void
    {
        $rights = new Entitlements($this->entityManager);
        $host = $this->user('host');
        $soon = $rights->grant($host, 'pass-soon', [], new \DateTimeImmutable('+3 days'));
        $later = $rights->grant($host, 'pass-later', [], new \DateTimeImmutable('+3 months'));

        $ending = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EntitlementEndingEvent::class, static function (EntitlementEndingEvent $e) use (&$ending): void { $ending[] = $e->entitlement->getId(); });
        $subscriptions = new Subscriptions($this->entityManager, new PaymentGatewayRegistry([]), $dispatcher, null, 7);
        $subscriptions->sweep();
        $subscriptions->sweep();

        self::assertContains($soon->getId(), $ending);
        self::assertNotContains($later->getId(), $ending);
        self::assertSame(1, \count(array_keys($ending, $soon->getId(), true)));
        self::assertInstanceOf(Entitlement::class, $soon);
    }
}
