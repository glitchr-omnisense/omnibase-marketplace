<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Entitlement;
use Base\Marketplace\Service\EntitlementException;
use Base\Marketplace\Service\Entitlements;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;

/** Rights: switches, values, ceilings, counts consumed and given back, resources, time. */
final class EntitlementsTest extends MarketplaceKernelTestCase
{
    private function entitlements(): Entitlements
    {
        return new Entitlements($this->entityManager);
    }

    public function testSwitchesValuesAndCeilings(): void
    {
        $rights = $this->entitlements();
        $host = $this->user('host');
        self::assertFalse($rights->allows($host, 'list'));
        self::assertNull($rights->limit($host, 'guests'));

        $rights->grant($host, 'plan-a', ['list' => true, 'guests' => 80, 'commission' => 0.03, 'export' => false], null, ['level' => 1]);
        $rights->grant($host, 'plan-c', ['guests' => 300, 'commission' => 0.015, 'export' => true], null, ['level' => 3]);

        self::assertTrue($rights->allows($host, 'list'), 'a switch on in one of them');
        self::assertTrue($rights->allows($host, 'export'));
        self::assertFalse($rights->allows($host, 'ai'));
        self::assertSame(300, $rights->limit($host, 'guests'), 'the largest ceiling');
        self::assertSame(0.015, $rights->value($host, 'commission'), 'the highest plan\'s rate');
        self::assertSame('none', $rights->value($host, 'support', 'none'));
        self::assertTrue($rights->holds($host, 'plan-a'));
        self::assertFalse($rights->holds($this->user('other'), 'plan-a'));
    }

    public function testACountIsConsumedAndGivenBack(): void
    {
        $rights = $this->entitlements();
        $host = $this->user('host');
        $plan = $rights->grant($host, 'plan-b', ['events.major' => 1, 'events.extra' => 2]);

        self::assertSame(2, $rights->remaining($host, 'events.extra'));
        $rights->consume($host, 'events.extra', 1, 'occasion', 11);
        $rights->consume($host, 'events.extra', 1, 'occasion', 12);
        self::assertSame(0, $rights->remaining($host, 'events.extra'));
        self::assertFalse($rights->allows($host, 'events.extra'));
        self::assertSame($plan->getId(), $rights->coveredBy($host, 'events.extra', 'occasion', 12)?->getId());

        try {
            $rights->consume($host, 'events.extra', 1, 'occasion', 13);
            self::fail('The third complementary event is not granted.');
        } catch (EntitlementException $e) {
            self::assertSame('events.extra', $e->key);
        }
        self::assertTrue($this->entityManager->isOpen(), 'a refusal is an answer, not a failure');

        self::assertSame(1, $rights->release($host, 'events.extra', 'occasion', 11));
        self::assertSame(1, $rights->remaining($host, 'events.extra'));
        $rights->consume($host, 'events.extra', 1, 'occasion', 13);

        // A second plan adds its counts.
        $rights->grant($host, 'plan-b', ['events.major' => 1, 'events.extra' => 2]);
        self::assertSame(2, $rights->remaining($host, 'events.major'));
        self::assertSame(2, $rights->remaining($host, 'events.extra'));
    }

    public function testAnEntitlementForOneResource(): void
    {
        $rights = $this->entitlements();
        $pupil = $this->user('pupil');
        $rights->grant($pupil, 'course', [], null, ['type' => 'classroom_course', 'id' => 42]);

        self::assertTrue($rights->holds($pupil, 'course', 'classroom_course', 42));
        self::assertFalse($rights->holds($pupil, 'course', 'classroom_course', 43));
        self::assertFalse($rights->holds($pupil, 'course'));
        self::assertCount(1, $rights->active($pupil, 'classroom_course', '42'));
        self::assertCount(0, $rights->active($pupil), 'not a right over everything');
    }

    public function testTimeAndRevocation(): void
    {
        $rights = $this->entitlements();
        $host = $this->user('host');
        $pass = $rights->grant($host, 'pass', ['guests' => 30], new \DateTimeImmutable('+12 months'));

        self::assertTrue($pass->isActive());
        self::assertFalse($pass->isActive(new \DateTimeImmutable('+13 months')));
        self::assertSame(30, $rights->limit($host, 'guests'));
        self::assertCount(0, $rights->active($host, at: new \DateTimeImmutable('+13 months')));

        $pass->revoke();
        $this->entityManager->flush();
        self::assertNull($rights->limit($host, 'guests'));
        self::assertInstanceOf(Entitlement::class, $this->entityManager->getRepository(Entitlement::class)->find($pass->getId()));
    }
}
