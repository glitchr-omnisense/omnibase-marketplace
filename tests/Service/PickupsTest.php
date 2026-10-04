<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Pickup;
use Base\Marketplace\Enum\PickupMode;
use Base\Marketplace\Enum\PickupStatus;
use Base\Marketplace\Event\PickupChangedEvent;
use Base\Marketplace\Repository\Order\PickupRepository;
use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\Pickups;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Handing an order over: the modes offered, the postcodes delivered, the slots of a day, the steps. */
final class PickupsTest extends TestCase
{
    /** @var list<object> */
    private array $events = [];

    private function pickups(array $modes = ['pickup', 'delivery'], array $zips = ['67000', '67100', '674*'], ?Pickup $existing = null): Pickups
    {
        $repository = $this->createMock(PickupRepository::class);
        $repository->method('of')->willReturn($existing);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(PickupChangedEvent::class, function (PickupChangedEvent $event): void { $this->events[] = $event; });

        return new Pickups($this->createMock(EntityManagerInterface::class), $repository, $dispatcher, $this->createMock(UrlGeneratorInterface::class), $modes, $zips, ['from' => '11:00', 'to' => '14:00', 'step' => 30], 60, 3, 'Europe/Paris');
    }

    private function order(bool $paid = false): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('isPaid')->willReturn($paid);

        return $order;
    }

    public function testThePostcodesTheShopDeliversTo(): void
    {
        $pickups = $this->pickups();
        self::assertTrue($pickups->deliversTo('67000'));
        self::assertTrue($pickups->deliversTo(' 67 100 '));
        self::assertTrue($pickups->deliversTo('67400'), 'a prefix: 674*');
        self::assertFalse($pickups->deliversTo('68000'));
        self::assertFalse($pickups->deliversTo(''));
        self::assertFalse($this->pickups(zips: [])->deliversTo('67000'), 'no list: nowhere');
    }

    public function testTheSlotsOfADayLeaveOutThoseGoneAndTheNotice(): void
    {
        $pickups = $this->pickups();
        $zone = new \DateTimeZone('Europe/Paris');
        $morning = new \DateTimeImmutable('2026-10-06 08:00', $zone);

        self::assertSame(['11:00-11:30', '11:30-12:00', '12:00-12:30', '12:30-13:00', '13:00-13:30', '13:30-14:00'], $pickups->slots($morning, $morning));
        $noon = new \DateTimeImmutable('2026-10-06 11:40', $zone);
        self::assertSame(['13:00-13:30', '13:30-14:00'], $pickups->slots($noon, $noon), 'an hour of notice');
        $evening = new \DateTimeImmutable('2026-10-06 20:00', $zone);
        self::assertSame([], $pickups->slots($evening, $evening));
        self::assertCount(3, $pickups->days($evening), 'today is over: the three days after');
        self::assertCount(4, $pickups->days($morning));
    }

    public function testAHandOverOpenedThenMoved(): void
    {
        $pickups = $this->pickups();
        $day = $pickups->now()->modify('+1 day');
        $pickup = $pickups->open($this->order(), PickupMode::DELIVERY, $day, ['contactName' => 'Léa Martin', 'phone' => '06 00 00 00 00', 'address' => '12 rue des Lilas', 'postcode' => '67000', 'city' => 'Strasbourg', 'slot' => '12:00-12:30']);

        self::assertSame(PickupStatus::CHECKOUT, $pickup->getStatus());
        self::assertSame('67000', $pickup->getPostcode());
        self::assertSame(43, \strlen($pickup->getToken()));
        self::assertSame(0, $pickup->getStep());
        self::assertCount(7, $pickup->getFlow(), 'a delivery goes out before it is done');

        $pickups->move($pickup, PickupStatus::RECEIVED);
        $pickups->move($pickup, PickupStatus::RECEIVED);
        self::assertCount(2, $this->events, 'opened, received - the same status again is nothing');
        self::assertSame(PickupStatus::CHECKOUT, $this->events[1]->previous);
        self::assertSame(1, $pickup->getStep());

        $pickups->move($pickup, PickupStatus::REFUSED);
        self::assertSame(-1, $pickup->getStep());
        self::assertFalse($pickup->isOpen());
    }

    public function testAPickupAtTheShopNeedsNoAddressAndHasNoRound(): void
    {
        $pickup = $this->pickups()->open($this->order(), PickupMode::PICKUP, new \DateTimeImmutable('+2 days'), ['address' => 'ignored', 'postcode' => '75001']);
        self::assertNull($pickup->getAddress());
        self::assertNull($pickup->getPostcode());
        self::assertNotContains(PickupStatus::OUT_FOR_DELIVERY, $pickup->getFlow());
    }

    public function testAnOrderPaidAtOnceIsReceivedAtOnce(): void
    {
        $pickup = $this->pickups()->open($this->order(true), PickupMode::PICKUP, new \DateTimeImmutable('+1 day'));
        self::assertSame(PickupStatus::RECEIVED, $pickup->getStatus());
    }

    /** @dataProvider refusals */
    public function testRefusals(string $key, PickupMode $mode, string $day, array $details): void
    {
        $this->expectException(CartException::class);
        $this->expectExceptionMessage($key);
        $this->pickups()->open($this->order(), $mode, new \DateTimeImmutable($day), $details);
    }

    public static function refusals(): iterable
    {
        yield 'a mode the shop does not offer' => ['pickup.error.mode', PickupMode::SHIPPING, '+1 day', []];
        yield 'a day gone' => ['pickup.error.day', PickupMode::PICKUP, '-2 days', []];
        yield 'out of the zone' => ['pickup.error.out_of_zone', PickupMode::DELIVERY, '+1 day', ['postcode' => '75011', 'address' => '1 rue X']];
        yield 'no address' => ['pickup.error.address', PickupMode::DELIVERY, '+1 day', ['postcode' => '67000']];
    }

    public function testAnOrderAlreadyPlacedKeepsItsHandOver(): void
    {
        $placed = (new Pickup($this->order(), new \DateTimeImmutable('+1 day')))->setStatus(PickupStatus::ACCEPTED);
        $this->expectExceptionMessage('pickup.error.placed');
        $this->pickups(existing: $placed)->open($this->order(), PickupMode::PICKUP, new \DateTimeImmutable('+1 day'));
    }
}
