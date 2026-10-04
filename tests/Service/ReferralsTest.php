<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Enum\ReferralStatus;
use Base\Marketplace\Event\ReferralQualifyingEvent;
use Base\Marketplace\Service\Credits;
use Base\Marketplace\Service\ReferralException;
use Base\Marketplace\Service\Referrals;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/** Referrals: a code each, a referee once, the reward after the cooling-off period, and what is refused. */
final class ReferralsTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    private function referrals(int $cap = 10, ?EventDispatcher $dispatcher = null): Referrals
    {
        $nothing = ['type' => null];
        $credit = ['type' => 'credit', 'credit_kind' => 'stationery', 'credit_quantity' => 1000];

        return new Referrals($this->entityManager, new Credits($this->entityManager), $dispatcher, true, 14, $cap, $nothing, $credit);
    }

    public function testACodeEachAndAReferralOnce(): void
    {
        $referrals = $this->referrals();
        $lea = $this->user('lea');
        $code = $referrals->codeFor($lea);
        self::assertSame(8, \strlen($code->getCode()));
        self::assertSame($code->getId(), $referrals->codeFor($lea)->getId());
        self::assertSame($code->getId(), $referrals->find(strtolower($code->getCode()))->getId(), 'typed in lower case');
        self::assertNull($referrals->find('NOPE0000'));

        $tom = $this->user('tom');
        $referral = $referrals->attribute($code->getCode(), $tom);
        self::assertSame(ReferralStatus::PENDING, $referral->getStatus());
        self::assertSame($lea->getId(), $referral->getReferrer()->getId());
        self::assertCount(1, $referrals->referralsBy($lea));

        foreach (['self' => $lea, 'already' => $tom] as $reason => $who) {
            try {
                $referrals->attribute($code->getCode(), $who);
                self::fail($reason);
            } catch (ReferralException $e) {
                self::assertSame($reason, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('unknown');
        $referrals->attribute('NOPE0000', $this->user('zoe'));
    }

    public function testTheSamePersonUnderAnotherAddressIsRefused(): void
    {
        self::assertSame('leamartin@gmail.com', Referrals::canonicalEmail(' Lea.Martin+promo@GoogleMail.com '));
        self::assertSame('lea.martin@example.org', Referrals::canonicalEmail('lea.martin+x@example.org'));

        $referrals = $this->referrals();
        $lea = $this->user('lea');
        $twin = $this->user('twin');
        $twin->setEmail(str_replace('@', '+bis@', $lea->getEmail()));
        $this->entityManager->flush();

        $this->expectExceptionMessage('email');
        $referrals->attribute($referrals->codeFor($lea)->getCode(), $twin);
    }

    public function testTheReferrerIsRewardedOnceTheOrderHasHeld(): void
    {
        $referrals = $this->referrals();
        $credits = new Credits($this->entityManager);
        $lea = $this->user('lea');
        $tom = $this->user('tom');
        $referral = $referrals->attribute($referrals->codeFor($lea)->getCode(), $tom);

        $order = $this->paidOrder($tom, [[$this->goods(), 1]]);
        $referrals->onOrderPaid($order);
        self::assertSame(ReferralStatus::QUALIFIED, $referral->getStatus());
        self::assertSame((string) $order->getReference(), $referral->getOrderReference());

        self::assertSame(['rewarded' => 0, 'rejected' => 0], $referrals->release(), 'the cooling-off period has not passed');
        self::assertSame(0, $credits->balance($lea, 'stationery'));

        self::assertSame(['rewarded' => 1, 'rejected' => 0], $referrals->release(new \DateTimeImmutable('+15 days')));
        self::assertSame(ReferralStatus::REWARDED, $referral->getStatus());
        self::assertSame(1000, $credits->balance($lea, 'stationery'));
        self::assertCount(1, $referrals->rewardsOf($lea));

        // A second order changes nothing; a second release neither.
        $referrals->onOrderPaid($this->paidOrder($tom, [[$this->goods(), 1]]));
        self::assertSame(['rewarded' => 0, 'rejected' => 0], $referrals->release(new \DateTimeImmutable('+40 days')));
        self::assertSame(1000, $credits->balance($lea, 'stationery'));
    }

    public function testSomebodyWhoAlreadyBoughtIsNoReferee(): void
    {
        $referrals = $this->referrals();
        $tom = $this->user('tom');
        $this->paidOrder($tom, [[$this->goods(), 1]]);

        $this->expectExceptionMessage('customer');
        $referrals->attribute($referrals->codeFor($this->user('lea'))->getCode(), $tom);
    }

    public function testTheSameCardARefundAndTheCeilingRefuse(): void
    {
        $referrals = $this->referrals(1);
        $lea = $this->user('lea');
        $this->paidOrder($lea, [[$this->goods(), 1]], ['fingerprint' => 'fp_lea']);
        $code = $referrals->codeFor($lea)->getCode();

        // Paid with the referrer's own card.
        $twin = $this->user('twin');
        $referral = $referrals->attribute($code, $twin);
        $referrals->onOrderPaid($this->paidOrder($twin, [[$this->goods(), 1]], ['fingerprint' => 'fp_lea']));
        self::assertSame(ReferralStatus::REJECTED, $referral->getStatus());
        self::assertSame('card', $referral->getRejection());

        // Two honest referees, a ceiling of one reward a month.
        $first = $referrals->attribute($code, $a = $this->user('a'));
        $second = $referrals->attribute($code, $b = $this->user('b'));
        $referrals->onOrderPaid($this->paidOrder($a, [[$this->goods(), 1]], ['fingerprint' => 'fp_a']));
        $referrals->onOrderPaid($this->paidOrder($b, [[$this->goods(), 1]]));
        self::assertSame(['rewarded' => 1, 'rejected' => 1], $referrals->release(new \DateTimeImmutable('+15 days')));
        self::assertSame(ReferralStatus::REWARDED, $first->getStatus());
        self::assertSame('cap', $second->getRejection());

        // A listener's own reason; a review before the reward.
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ReferralQualifyingEvent::class, static function (ReferralQualifyingEvent $e): void { $e->rejection = 'same household'; });
        $picky = $this->referrals(10, $dispatcher);
        $third = $picky->attribute($code, $c = $this->user('c'));
        $picky->onOrderPaid($this->paidOrder($c, [[$this->goods(), 1]]));
        self::assertSame('same household', $third->getRejection());

        $fourth = $referrals->attribute($code, $d = $this->user('d'));
        $referrals->onOrderPaid($this->paidOrder($d, [[$this->goods(), 1]]));
        $referrals->reject($fourth);
        self::assertSame('review', $fourth->getRejection());
        self::assertSame(['rewarded' => 0, 'rejected' => 0], $this->referrals()->release(new \DateTimeImmutable('+15 days')));
    }
}
