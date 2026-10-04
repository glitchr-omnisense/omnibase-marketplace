<?php

namespace Tests\Base\Marketplace\Wishlist;

use Base\Marketplace\Entity\Wishlist\Contribution;
use Base\Marketplace\Entity\Wishlist\Wishlist;
use Base\Marketplace\Enum\ContributionStatus;
use Base\Marketplace\Enum\ReservationStatus;
use Base\Marketplace\Enum\WishlistItemKind;
use Base\Marketplace\Enum\WishlistKind;
use Base\Marketplace\Event\ContributionPaidEvent;
use Base\Marketplace\Event\ContributionPreparingEvent;
use Base\Marketplace\Event\PaymentNotificationEvent;
use Base\Marketplace\EventListener\WishlistNotificationListener;
use Base\Marketplace\Model\WishlistHolderInterface;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Base\Marketplace\Wishlist\Contributions;
use Base\Marketplace\Wishlist\PayoutAccounts;
use Base\Marketplace\Wishlist\ProductLookup;
use Base\Marketplace\Wishlist\Reservations;
use Base\Marketplace\Wishlist\WishlistException;
use Base\Marketplace\Wishlist\Wishlists;
use Omnitrade\Model\Account;
use Omnitrade\Model\Notification;
use Omnitrade\Model\Status;
use Omnitrade\Registry;
use Omnitrade\Request\Purchase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;

/** Lists: wishes read from an address, objects reserved once, money given to the owner's account. */
final class WishlistTest extends MarketplaceKernelTestCase
{
    private StubProvider $provider;
    private Registry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Account::class)) {
            self::markTestSkipped('Needs glitchr/omnitrade with Connect.');
        }
        $this->provider = new StubProvider();
        $this->registry = new Registry([$this->provider], ['stripe' => ['factory' => 'stub'], 'web' => ['factory' => 'stub']]);
    }

    private function gateways(): OmnitradeGateways
    {
        return new OmnitradeGateways($this->registry, $this->createMock(UrlGeneratorInterface::class));
    }

    private function wishlists(): Wishlists
    {
        return new Wishlists($this->entityManager, new ProductLookup($this->registry, ['amazon', 'web']));
    }

    public function testAWishIsReadFromItsAddress(): void
    {
        $holder = new class implements WishlistHolderInterface {
            public function getWishlistHolderType(): string { return 'occasion'; }
            public function getWishlistHolderId(): int { return 77; }
        };
        $lists = $this->wishlists();
        $list = $lists->create($this->user('parents'), 'Liste de naissance', WishlistKind::BIRTH, $holder);
        self::assertTrue($list->isSurprise(), 'a birth list is a surprise by default');
        self::assertSame(24, \strlen($list->getToken()));

        $item = $lists->addFromUrl($list, 'https://boutique.example/p/poussette-yoyo');
        self::assertSame('Poussette Yoyo', $item->getTitle());
        self::assertSame(44990, $item->getPrice());
        self::assertSame('La Boutique', $item->getMerchant());
        self::assertSame('https://cdn.test/yoyo.jpg', $item->getImageUrl());
        self::assertSame('web', $item->getGateway(), 'amazon is not configured: the next gateway answered');
        self::assertSame('https://boutique.example/p/poussette-yoyo?aff=1', $item->getBuyUrl());
        self::assertNotNull($item->getPriceFetchedAt());

        $unknown = $lists->addFromUrl($list, 'https://www.artisan.example/berceau');
        self::assertSame('artisan.example', $unknown->getTitle(), 'nothing read: kept with its address');
        self::assertNull($unknown->getPrice());
        self::assertSame('https://www.artisan.example/berceau', $unknown->getBuyUrl());

        $found = $this->entityManager->getRepository(Wishlist::class)->findForHolder($holder);
        self::assertCount(1, $found);
        self::assertSame($list->getId(), $found[0]->getId());

        $this->expectException(WishlistException::class);
        $lists->addFromUrl($list, 'javascript:alert(1)');
    }

    public function testOldPricesAreReadAgain(): void
    {
        $lists = $this->wishlists();
        $list = $lists->create($this->user('host'), 'Liste');
        $item = $lists->addFromUrl($list, 'https://boutique.example/p/poussette-yoyo');
        $item->setPrice(39990);
        $this->entityManager->flush();

        self::assertSame(0, $lists->refreshPrices(), 'read today: not again');
        self::assertGreaterThanOrEqual(1, $lists->refreshPrices(new \DateTimeImmutable('+2 days')));
        self::assertSame(44990, $item->getPrice());
    }

    public function testAnObjectIsReservedOnce(): void
    {
        $lists = $this->wishlists();
        $reservations = new Reservations($this->entityManager, 30);
        $list = $lists->create($this->user('host'), 'Liste de mariage');
        $item = $lists->addFromUrl($list, 'https://boutique.example/p/poussette-yoyo')->setQuantity(2);
        $this->entityManager->flush();

        ['reservation' => $first, 'token' => $token] = $reservations->reserve($item, 1, 'Tante Odile', 'odile@example.org', 'household-3');
        self::assertSame(ReservationStatus::HELD, $first->getStatus());
        self::assertSame(1, $item->getAvailable());
        $reservations->reserve($item, 1, 'Paul');
        self::assertTrue($item->isFulfilled());

        try {
            $reservations->reserve($item, 1, 'Trop tard');
            self::fail('Both are taken.');
        } catch (WishlistException $e) {
            self::assertSame('wishlist.error.taken', $e->getMessage());
            self::assertSame(['{left}' => 0], $e->parameters);
        }
        self::assertTrue($this->entityManager->isOpen());

        self::assertSame(ReservationStatus::CANCELLED, $reservations->cancel($token)->getStatus());
        self::assertNull($reservations->cancel('not-a-token'));
        $this->entityManager->refresh($item);
        self::assertSame(1, $item->getAvailable(), 'back on the list');

        ['reservation' => $again] = $reservations->reserve($item, 1, 'Camille');
        self::assertGreaterThanOrEqual(1, $reservations->expire(new \DateTimeImmutable('+31 days')), 'the promises lapse');
        $this->entityManager->refresh($again);
        self::assertSame(ReservationStatus::EXPIRED, $again->getStatus());

        $fund = $lists->addFund($list, 'Voyage de noces');
        $this->expectException(WishlistException::class);
        $this->expectExceptionMessage('wishlist.error.not_reservable');
        $reservations->reserve($fund, 1, 'Paul');
    }

    public function testAContributionIsPaidToTheOwnersAccount(): void
    {
        $paid = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ContributionPaidEvent::class, static function (ContributionPaidEvent $e) use (&$paid): void { $paid[] = $e->contribution->getReference(); });
        $dispatcher->addListener(ContributionPreparingEvent::class, static function (ContributionPreparingEvent $e): void { $e->fee = (int) round($e->contribution->getAmount() * 0.02); });

        $lists = $this->wishlists();
        $accounts = new PayoutAccounts($this->entityManager, $this->gateways(), 'stripe');
        $contributions = new Contributions($this->entityManager, $this->gateways(), $dispatcher, 0.03, 0, 500);
        $host = $this->user('host');
        $list = $lists->create($host, 'Liste de mariage');
        $fund = $lists->addFund($list, 'Voyage de noces');

        try {
            $contributions->start($fund, 5000, 'Tom', 'https://site.test/merci');
            self::fail('No payout account yet.');
        } catch (WishlistException $e) {
            self::assertSame('wishlist.error.no_payout', $e->getMessage());
        }

        self::assertTrue($accounts->isAvailable());
        $account = $accounts->open($host, 'fr');
        self::assertSame($account->getId(), $accounts->open($host)->getId(), 'one per owner');
        self::assertFalse($account->isReady());
        self::assertSame(['external_account'], $account->getRequirements());
        self::assertSame('https://connect.test/'.$account->getReference(), $accounts->onboardingUrl($account, 'https://site.test/retour', 'https://site.test/reprendre'));
        $this->provider->accountReady = true;
        self::assertTrue($accounts->refresh($account)->isReady());
        $list->setPayoutAccount($account);
        $this->entityManager->flush();
        self::assertTrue($list->takesContributions());

        $contribution = $contributions->start($fund, 5000, 'Tom', 'https://site.test/merci', null, ['email' => 'tom@example.org', 'message' => 'Bon voyage !', 'coverFees' => true, 'giverReference' => 'household-9']);
        self::assertSame('https://pay.test/'.$contribution->getReference(), $contribution->getRedirectUrl());
        self::assertSame(100, $contribution->getFee(), 'the listener\'s rate: 2 %');
        self::assertSame(5100, $contribution->getCharged(), 'the giver covers the fee');
        self::assertSame(5000, $contribution->getReceived(), 'the owner receives the gift whole');

        $purchase = array_values(array_filter($this->provider->requests, static fn ($r) => $r instanceof Purchase))[0];
        self::assertSame(5100, $purchase->payment->amount->amount);
        self::assertSame($account->getReference(), $purchase->payment->destination, 'paid to the owner, never to the platform');
        self::assertSame(100, $purchase->payment->applicationFee->amount);
        self::assertSame($contribution->getReference(), $purchase->payment->metadata['contribution']);

        // Back from the page, not paid yet; then paid - told once, whoever says it.
        self::assertSame(ContributionStatus::PENDING, $contributions->confirm($contribution)->getStatus());
        $this->provider->paymentStatus = Status::PAID;
        self::assertTrue($contributions->confirm($contribution)->isPaid());
        $listener = new WishlistNotificationListener($contributions, $accounts);
        $listener($event = new PaymentNotificationEvent('stripe', new Notification('stub', 'checkout.session.completed', $contribution->getProviderReference(), Status::PAID)));
        self::assertSame('contribution', $event->getOutcome());
        self::assertSame([$contribution->getReference()], $paid);
        $this->entityManager->refresh($fund);
        self::assertSame(5000, $fund->getCollected());
        self::assertSame(5000, $list->getCollected());

        // Without covering the fee, it comes out of the gift.
        $second = $contributions->start($fund, 2000, 'Zoé', 'https://site.test/merci');
        self::assertSame(2000, $second->getCharged());
        self::assertSame(1960, $second->getReceived());
        $listener(new PaymentNotificationEvent('stripe', new Notification('stub', 'checkout.session.expired', $second->getProviderReference(), Status::EXPIRED)));
        self::assertSame(ContributionStatus::CANCELLED, $second->getStatus());

        // The provider's word on the account.
        $listener($event = new PaymentNotificationEvent('stripe', new Notification('stub', 'account.updated', $account->getReference(), account: new Account('stub', $account->getReference(), requirements: ['individual.verification.document']))));
        self::assertSame('payout account', $event->getOutcome());
        self::assertFalse($account->isReady());
        self::assertFalse($list->takesContributions());
    }

    public function testASharedGiftTakesNoMoreThanItsPrice(): void
    {
        $lists = $this->wishlists();
        $accounts = new PayoutAccounts($this->entityManager, $this->gateways(), 'stripe');
        $contributions = new Contributions($this->entityManager, $this->gateways(), null, 0.03, 0, 500);
        $host = $this->user('host');
        $list = $lists->create($host, 'Liste');
        $this->provider->accountReady = true;
        $list->setPayoutAccount($accounts->refresh($accounts->open($host)));
        $share = $lists->addFund($list, 'Robot pâtissier', 30000, WishlistItemKind::SHARE);

        self::assertSame(30000, $share->getRemaining());
        foreach (['wishlist.error.minimum' => 100, 'wishlist.error.too_much' => 30100] as $error => $amount) {
            try {
                $contributions->start($share, $amount, 'Tom', 'https://site.test/merci');
                self::fail($error);
            } catch (WishlistException $e) {
                self::assertSame($error, $e->getMessage());
            }
        }
        $object = $lists->addFromUrl($list, 'https://boutique.example/p/poussette-yoyo');
        $this->expectExceptionMessage('wishlist.error.not_fund');
        $contributions->start($object, 5000, 'Tom', 'https://site.test/merci');
    }
}
