<?php

namespace Tests\Base\Marketplace\Persistence;

use Base\Contract\Locales;
use Base\Contract\Runtime;
use Base\Testing\Engines;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use App\Entity\User; // the host's user, Base\Entity\User where it has none (ClassAliases, registered by Engines::of())
use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Entity\Sales\Region;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Entity\Wishlist\Item;
use Base\Marketplace\Entity\Wishlist\Reservation;
use Base\Marketplace\Entity\Wishlist\Wishlist;
use Base\Marketplace\Enum\ReservationStatus;
use Base\Marketplace\Invoice\Transmission\Entity\InvoiceFlow;
use Base\Marketplace\Repository\Invoice\InvoiceFlowRepository;
use Base\Marketplace\Repository\ProductRepository;
use Base\Marketplace\Repository\QuoteRepository;
use Base\Marketplace\Repository\Sales\RegionRepository;
use Base\Marketplace\Repository\StoreRepository;
use Base\Marketplace\Repository\Wishlist\ReservationRepository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The marketplace's queries once written per persistence, now written once in the language, on both engines:
 * the currencies in use, the lapsed reservations, the flows with a declaration not sent, the quotes' numbering - the
 * same answers on the same rows.
 */
final class StatementsParityTest extends TestCase
{
    private static Engines $engines;

    public static function backends(): array
    {
        return Engines::backends();
    }

    public static function setUpBeforeClass(): void
    {
        // The entities' constructors read the runtime's locale (User::setLocale()): one, as a host gives it.
        if (!Runtime::has(Locales::class)) {
            Runtime::set(Locales::class, new class implements Locales {
                public function getLocale(): string { return 'fr'; }
                public function getDefaultLocale(): string { return 'fr'; }
                public function getAvailableLocales(): array { return ['fr']; }
                public function getTimezone(): string { return 'UTC'; }
            });
        }
        // A store reads its defaults from the host's parameters: the bundle's, as its configuration gives them.
        if (!\Base\Contract\Runtime::has(\Base\Service\ParameterBagInterface::class)) {
            \Base\Contract\Runtime::set(\Base\Service\ParameterBagInterface::class, new \Base\Service\ArrayParameterBag(['marketplace.default_currency' => 'EUR']));
        }
        self::$engines = Engines::of([Product::class, Store::class, Region::class, Reservation::class, Quote::class, InvoiceFlow::class], ['project_dir' => \dirname(__DIR__, 2), 'resolve_target_entities' => ['Base\\Marketplace\\Model\\MerchantInterface' => User::class]]);
        $seed = [];
        $owner = self::user('seller@example.org');
        $seed[] = $owner;
        foreach ([['EUR', 'p-eur'], ['USD', 'p-usd'], ['EUR', 'p-eur-2']] as [$currency, $slug]) {
            $product = new Product(null, null, 0, $currency);
            $product->setTitle($slug);
            $product->setSlug($slug);
            $seed[] = $product;
        }
        $seed[] = (new Store())->setCurrency('JPY');
        $seed[] = (new Region())->setCurrency('CHF')->setEnabled(true);

        $wishlist = new Wishlist($owner, 'Atelier Exemple');
        $item = (new Item('A chair'))->setWishlist($wishlist);
        $seed[] = $wishlist;
        $seed[] = $item;
        foreach ([['lapsed', '2026-01-01'], ['kept', '2099-01-01'], ['lapsed-too', '2026-01-02']] as [$name, $until]) {
            $seed[] = new Reservation($item, 1, $name, null, hash('sha256', $name), new \DateTimeImmutable($until));
        }
        $confirmed = new Reservation($item, 1, 'confirmed', null, hash('sha256', 'confirmed'), new \DateTimeImmutable('2026-01-01'));
        $confirmed->setStatus(ReservationStatus::CONFIRMED);
        $seed[] = $confirmed;

        foreach ([1 => true, 2 => false] as $sequence => $sent) {
            $invoice = new Invoice(Invoice::TYPE_INVOICE, 'F', 2026, $sequence, 'F-2026-'.$sequence, new \DateTimeImmutable('2026-01-01'), null, 'EUR', [], [], [], []);
            $flow = new InvoiceFlow($invoice, 'test', 'flow-'.$sequence);
            (new \ReflectionProperty(InvoiceFlow::class, 'declared'))->setValue($flow, [['status' => 'issued', 'at' => '2026-01-01T00:00:00+00:00', 'sent' => $sent]]);
            $seed[] = $invoice;
            $seed[] = $flow;
        }
        self::$engines->seed(...$seed);
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTheCurrenciesInUse(string $backend): void
    {
        $finders = self::$engines->finders($backend);
        $sorted = static function (array $currencies): array {
            sort($currencies);

            return $currencies;
        };

        self::assertSame(['EUR', 'USD'], $sorted((new ProductRepository($finders))->currencies()), 'each once');
        self::assertContains('JPY', (new StoreRepository($finders))->currencies());
        self::assertContains('CHF', (new RegionRepository($finders))->currencies());
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTheLapsedReservationsExpire(string $backend): void
    {
        $table = self::$engines->description()->entities[Reservation::class];
        // Each backend starts from the same rows: the promises the other one let go are held again.
        self::$engines->pdo()->exec(sprintf("UPDATE %s SET %s = 'held' WHERE %s IN ('lapsed', 'lapsed-too')", $table->table, $table->fields['status']->column, $table->fields['name']->column));

        $reservations = (new ReservationRepository(self::$engines->finders($backend)));
        self::assertSame(2, $reservations->expire(new \DateTimeImmutable('2026-06-01')));
        $statuses = [];
        foreach ((new ReservationRepository(self::$engines->finders($backend)))->findBy([], ['name' => 'ASC']) as $reservation) {
            $statuses[$reservation->getName()] = $reservation->getStatus()->value;
        }
        self::assertSame(['confirmed' => 'confirmed', 'kept' => 'held', 'lapsed' => 'expired', 'lapsed-too' => 'expired'], $statuses);
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTheFlowsWithADeclarationNotSent(string $backend): void
    {
        $flows = (new InvoiceFlowRepository(self::$engines->finders($backend)))->withUnsentDeclarations();

        self::assertSame(['flow-2'], array_map(static fn (InvoiceFlow $flow) => $flow->getReference(), $flows));
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTheQuotesAreNumberedInTurn(string $backend): void
    {
        $quotes = (new QuoteRepository(self::$engines->finders($backend)));
        $year = new \DateTimeImmutable('2030-06-01');
        $first = new Quote();
        $quotes->saveNumbered($first, $year);
        $second = new Quote();
        $quotes->saveNumbered($second, $year);

        // The doctrine case runs first: the eloquent one goes on from its numbers, on the same table.
        $start = 'doctrine' === $backend ? 1 : 3;
        self::assertSame(sprintf('D-2030-%04d', $start), $first->getReference());
        self::assertSame(sprintf('D-2030-%04d', $start + 1), $second->getReference());
    }

    /** A user as the host builds one: its own User may require more than an e-mail (a username, written when it has one). */
    private static function user(string $email): User
    {
        $user = new User($email);
        $accessor = PropertyAccess::createPropertyAccessor();
        if ($accessor->isWritable($user, 'username')) {
            $accessor->setValue($user, 'username', strstr($email, '@', true));
        }

        return $user;
    }
}
