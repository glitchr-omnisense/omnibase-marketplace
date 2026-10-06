<?php

namespace Tests\Base\Marketplace\Payment;

use Base\Marketplace\Catalogue\PlatformSynchronizer;
use Base\Marketplace\Enum\ContributionStatus;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Base\Marketplace\Payment\Omnitrade\TrialGatewayFactoryInterface;
use Base\Marketplace\Repository\Catalogue\PlatformLinkRepository;
use Base\Marketplace\Wishlist\Contributions;
use Base\Marketplace\Wishlist\PayoutAccounts;
use Base\Marketplace\Wishlist\ProductLookup;
use Base\Marketplace\Wishlist\WishlistException;
use Base\Marketplace\Wishlist\Wishlists;
use Omnitrade\Gateway;
use Omnitrade\GatewayInterface;
use Omnitrade\Model\Account;
use Omnitrade\Registry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\Wishlist\StubProvider;

/**
 * No money moves in a demonstration - not through a list either. The shop's
 * checkout was closed to glitchr/omnitrade's gateways there
 * (PaymentGatewayRegistry), but a list's contributions and its owner's
 * payout account (Connect) ask OmnitradeGateways themselves, and a
 * demonstration holding real keys would have charged a card and opened an
 * account at the provider. In `demo`, OmnitradeGateways answers for no
 * gateway of omnitrade.gateways at all: the only one it gives is made by a
 * factory the application marked as a trial one
 * (TrialGatewayFactoryInterface), asked by that factory's name.
 */
final class DemoOmnitradeTest extends MarketplaceKernelTestCase
{
    private StubProvider $provider;
    private Registry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Account::class)) {
            self::markTestSkipped('Needs glitchr/omnitrade with Connect.');
        }
        // "stripe" and "web" as an application configures them: here a provider that counts what it is asked.
        $this->provider = new StubProvider();
        $this->registry = new Registry([$this->provider], ['stripe' => ['factory' => 'stub'], 'web' => ['factory' => 'stub']]);
    }

    /** @param iterable<TrialGatewayFactoryInterface> $trials */
    private function gateways(string $environment, iterable $trials = []): OmnitradeGateways
    {
        return new OmnitradeGateways($this->registry, $this->createMock(UrlGeneratorInterface::class), null, null, $environment, $trials);
    }

    /** A gateway of the application's own that moves no money: accounts ready at once, a contribution paid on the spot. */
    private function trial(): TrialGatewayFactoryInterface
    {
        return new class implements TrialGatewayFactoryInterface {
            public ?StubProvider $made = null;

            public function getName(): string
            {
                return 'essai';
            }

            public function create(array $options = []): GatewayInterface
            {
                $this->made = new StubProvider();
                $this->made->accountReady = true;

                return new Gateway('essai', 'Trial payment (no charge)', [$this->made]);
            }
        };
    }

    public function testInDemoNoConfiguredGatewayAnswers(): void
    {
        $demo = $this->gateways('demo');

        self::assertTrue($demo->isDemonstration());
        self::assertNull($demo->get('stripe'), 'configured, and out of reach');
        self::assertNull($demo->get('web'));
        self::assertSame([], $demo->names());
        self::assertNotNull($this->gateways('prod')->get('stripe'), 'in production it is there');
        self::assertFalse($this->gateways('prod')->isDemonstration());
    }

    public function testAContributionIsRefusedAndTheProviderIsNotAsked(): void
    {
        // The list, its owner's account and a fund, as production left them.
        $live = $this->gateways('prod');
        $lists = new Wishlists($this->entityManager, new ProductLookup($live, ['web']));
        $accounts = new PayoutAccounts($this->entityManager, $live, 'stripe');
        $host = $this->user('host');
        $list = $lists->create($host, 'Liste de mariage');
        $this->provider->accountReady = true;
        $list->setPayoutAccount($accounts->refresh($accounts->open($host)));
        $fund = $lists->addFund($list, 'Voyage de noces');
        $this->entityManager->flush();
        $asked = \count($this->provider->requests);

        // The same list in a demonstration: nothing reaches the provider.
        $demo = $this->gateways('demo');
        $contributions = new Contributions($this->entityManager, $demo, null, 0.03, 0, 500);
        try {
            $contributions->start($fund, 5000, 'Zoé', 'https://site.test/merci');
            self::fail('A contribution reached a real gateway in a demonstration.');
        } catch (WishlistException $e) {
            self::assertSame('wishlist.error.payment', $e->getMessage());
        }
        self::assertCount($asked, $this->provider->requests, 'no purchase was sent');
        self::assertSame(0, $fund->getCollected());

        // Nor is an account opened, refreshed or sent to the provider's page.
        $demoAccounts = new PayoutAccounts($this->entityManager, $demo, 'stripe');
        self::assertFalse($demoAccounts->isAvailable());
        foreach ([
            fn () => $demoAccounts->open($this->user('other')),
            fn () => $demoAccounts->refresh($list->getPayoutAccount()),
            fn () => $demoAccounts->onboardingUrl($list->getPayoutAccount(), 'https://site.test/retour', 'https://site.test/encore'),
        ] as $call) {
            try {
                $call();
                self::fail('A payout account reached a real gateway in a demonstration.');
            } catch (WishlistException $e) {
                self::assertSame('wishlist.error.no_payout', $e->getMessage());
            }
        }
        self::assertCount($asked, $this->provider->requests);

        // An address pasted into a list is kept as it is: no page is read, no affiliate link asked.
        $lookup = new ProductLookup($demo, ['web']);
        self::assertNull($lookup->lookup('https://boutique.example/p/poussette-yoyo'));
        self::assertNull($lookup->affiliateLink('https://boutique.example/p/poussette-yoyo'));
        self::assertNotNull((new ProductLookup($live, ['web']))->lookup('https://boutique.example/p/poussette-yoyo'), 'in production the page is read');
        self::assertCount($asked + 1, $this->provider->requests, 'that one reading, and nothing from the demonstration');
    }

    public function testTheApplicationsTrialGatewayIsTheOneWayThrough(): void
    {
        $trial = $this->trial();
        $demo = $this->gateways('demo', [$trial]);

        self::assertSame(['essai'], $demo->names());
        self::assertNull($demo->get('stripe'), 'the real one stays out of reach');
        self::assertNotNull($demo->get('essai'));
        self::assertSame($demo->get('essai'), $demo->get('essai'), 'built once');

        $lists = new Wishlists($this->entityManager, new ProductLookup($demo, ['web']));
        $accounts = new PayoutAccounts($this->entityManager, $demo, 'essai');
        self::assertTrue($accounts->isAvailable());
        $host = $this->user('host');
        $list = $lists->create($host, 'Liste de mariage');
        $list->setPayoutAccount($accounts->refresh($accounts->open($host)));
        $fund = $lists->addFund($list, 'Voyage de noces');
        $this->entityManager->flush();

        $contribution = (new Contributions($this->entityManager, $demo, null, 0.03, 0, 500))->start($fund, 5000, 'Zoé', 'https://site.test/merci');
        self::assertSame(ContributionStatus::PENDING, $contribution->getStatus());
        self::assertSame('essai', $contribution->getGateway());
        self::assertNotEmpty($trial->made->requests, 'the trial gateway was asked');
        self::assertSame([], $this->provider->requests, 'the configured provider never was');

        // Outside a demonstration a trial factory opens nothing: the gateways are the configured ones.
        self::assertNull($this->gateways('prod', [$trial])->get('essai'));
    }

    public function testTheCatalogueReadsNoPlatform(): void
    {
        $arguments = [$this->entityManager, $this->createMock(PlatformLinkRepository::class), $this->registry, null, [], 'EUR'];

        self::assertNotNull((new PlatformSynchronizer(...[...$arguments, 'prod']))->gateway('stripe'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/demonstration/i');
        (new PlatformSynchronizer(...[...$arguments, 'demo']))->gateway('stripe');
    }
}
