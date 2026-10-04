<?php

namespace Tests\Base\Marketplace\Model;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Variant;
use Base\Marketplace\Enum\ProductKind;
use Base\Marketplace\Model\PlanTerms;
use PHPUnit\Framework\TestCase;

/** A plan's terms on a product, and a variant's laid over its principal's. */
final class PlanTermsTest extends TestCase
{
    public function testAPassAndASubscription(): void
    {
        $pass = PlanTerms::fromArray(['months' => 12, 'grants' => ['guests' => 80], 'credits' => ['ai' => '50'], 'level' => 1]);
        self::assertFalse($pass->isRecurring());
        self::assertSame(80, $pass->grant('guests'));
        self::assertSame(['ai' => 50], $pass->credits);
        $from = new \DateTimeImmutable('2026-10-04');
        self::assertSame('2027-10-04', $pass->expiry($from)->format('Y-m-d'));
        self::assertNull(PlanTerms::fromArray([])->expiry($from), 'no months: for good');

        $subscription = new PlanTerms(PlanTerms::RECURRING, 'month', grants: ['ai' => true]);
        self::assertTrue($subscription->isRecurring());
        self::assertNull($subscription->expiry($from), 'as long as it is paid');
        self::assertSame(['billing' => 'recurring', 'interval' => 'month', 'grants' => ['ai' => true], 'level' => 0], $subscription->toArray());
    }

    public function testARecurringPlanNeedsAnInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlanTerms(PlanTerms::RECURRING);
    }

    public function testAVariantChangesItsPrincipalsTerms(): void
    {
        $pass = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        self::assertSame(ProductKind::GOODS, $pass->getKind());
        $pass->setKind(ProductKind::PLAN);
        $pass->setPlan(new PlanTerms(months: 18, grants: ['events.major' => 1, 'guests' => 30, 'list' => true]));

        $large = (new \ReflectionClass(Variant::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\Base\Entity\Thread::class, 'parent'))->setValue($large, $pass);
        $large->setPlan(['grants' => ['guests' => 150]]);

        self::assertTrue($large->isPlan(), 'as its principal');
        self::assertFalse($large->isCreditPack());
        $terms = $large->getPlanTerms();
        self::assertSame(150, $terms->grant('guests'));
        self::assertTrue($terms->grant('list'));
        self::assertSame(18, $terms->months);
    }
}
