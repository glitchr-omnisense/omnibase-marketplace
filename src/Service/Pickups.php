<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Pickup;
use Base\Marketplace\Enum\PickupMode;
use Base\Marketplace\Enum\PickupStatus;
use Base\Marketplace\Event\PickupChangedEvent;
use Base\Marketplace\Repository\Order\PickupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handing an order over without the post, or beside it (Entity\Order\Pickup):
 * which ways the shop offers (marketplace.pickup.modes), where it brings
 * things itself (zip_codes: "67000", or "67*" for all that start so), the
 * days and the slots it gives, then the hand-over opened on an order and
 * moved along until it is done.
 *
 * What may travel how is the trade's business - a caterer's chilled dishes,
 * a florist's bouquets: the application (or its trade's bundle) filters the
 * modes before calling open().
 */
class Pickups
{
    /**
     * @param list<string> $modes
     * @param list<string> $zipCodes
     * @param array{from: string, to: string, step: int} $slots
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PickupRepository $pickups,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%marketplace.pickup.modes%')] private readonly array $modes = ['pickup'],
        #[Autowire('%marketplace.pickup.zip_codes%')] private readonly array $zipCodes = [],
        #[Autowire('%marketplace.pickup.slots%')] private readonly array $slots = ['from' => '11:00', 'to' => '19:00', 'step' => 30],
        #[Autowire('%marketplace.pickup.notice%')] private readonly int $notice = 60,
        #[Autowire('%marketplace.pickup.horizon%')] private readonly int $horizon = 14,
        #[Autowire('%marketplace.pickup.timezone%')] private readonly ?string $timezone = null,
    ) {
    }

    /** @return list<PickupMode> the ways this shop hands orders over */
    public function modes(): array
    {
        return array_values(array_filter(array_map(fn ($mode) => PickupMode::tryFrom((string) $mode), $this->modes)));
    }

    public function offers(PickupMode $mode): bool
    {
        return \in_array($mode, $this->modes(), true);
    }

    /** Whether the shop brings orders to this postcode itself. No list: nowhere. */
    public function deliversTo(?string $postcode, ?array $zipCodes = null): bool
    {
        $postcode = strtoupper(preg_replace('/\s+/', '', (string) $postcode));
        if ('' === $postcode) {
            return false;
        }
        foreach ($zipCodes ?? $this->zipCodes as $zip) {
            $zip = strtoupper(preg_replace('/\s+/', '', (string) $zip));
            if ($zip === $postcode || (str_ends_with($zip, '*') && str_starts_with($postcode, rtrim($zip, '*')))) {
                return true;
            }
        }

        return false;
    }

    /** The present, on the shop's clock (marketplace.pickup.timezone; PHP's zone without one). */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone ? new \DateTimeZone($this->timezone) : null);
    }

    /**
     * The slots of a day, "12:00-12:30": every `step` minutes between `from`
     * and `to`, those already gone today (and the notice the shop needs) left out.
     *
     * @return list<string>
     */
    public function slots(\DateTimeImmutable $day, ?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->now();
        $step = max(5, (int) ($this->slots['step'] ?? 30));
        $start = new \DateTimeImmutable($day->format('Y-m-d').' '.($this->slots['from'] ?? '11:00'), $now->getTimezone());
        $end = new \DateTimeImmutable($day->format('Y-m-d').' '.($this->slots['to'] ?? '19:00'), $now->getTimezone());
        $earliest = $now->modify(sprintf('+%d minutes', $this->notice));

        $slots = [];
        for ($at = $start; $at < $end; $at = $at->modify("+$step minutes")) {
            if ($at >= $earliest) {
                $slots[] = $at->format('H:i').'-'.min($at->modify("+$step minutes"), $end)->format('H:i');
            }
        }

        return $slots;
    }

    /** @return list<\DateTimeImmutable> the days an order may be for: from today (while a slot is left) to the horizon */
    public function days(?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->now();
        $days = [];
        for ($i = 0; $i <= $this->horizon; ++$i) {
            $day = $now->modify("+$i days")->setTime(0, 0);
            if ($this->slots($day, $now)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    public function of(Order $order): ?Pickup
    {
        return $this->pickups->of($order);
    }

    /**
     * The hand-over of an order, opened (or opened again while it is still
     * a cart): the mode, the day, and who - $details holds contactName,
     * email, phone, slot, address, postcode, city, country, note, token.
     *
     * @param array<string, ?string> $details
     *
     * @throws CartException with a key of the marketplace translations (pickup.error.*)
     */
    public function open(Order $order, PickupMode $mode, \DateTimeImmutable $day, array $details = [], bool $check = true): Pickup
    {
        if ($check) {
            if (!$this->offers($mode)) {
                throw new CartException('pickup.error.mode');
            }
            if ($day->setTime(0, 0) < $this->now()->setTime(0, 0)) {
                throw new CartException('pickup.error.day');
            }
            if (PickupMode::DELIVERY === $mode && !$this->deliversTo($details['postcode'] ?? null)) {
                throw new CartException('pickup.error.out_of_zone', ['{postcode}' => (string) ($details['postcode'] ?? '')]);
            }
            if ($mode->needsAddress() && '' === trim((string) ($details['address'] ?? ''))) {
                throw new CartException('pickup.error.address');
            }
        }

        $pickup = $this->pickups->of($order);
        if ($pickup && !$pickup->isAtCheckout()) {
            throw new CartException('pickup.error.placed');
        }
        if (!$pickup) {
            $pickup = new Pickup($order, $day, $mode, $details['token'] ?? null);
            $this->entityManager->persist($pickup);
        }
        $pickup->setMode($mode)->setDay($day)
            ->setContactName($details['contactName'] ?? null)
            ->setEmail($details['email'] ?? $order->getCustomer()?->getEmail())
            ->setPhone($details['phone'] ?? null)
            ->setSlot($details['slot'] ?? null)
            ->setAddress($mode->needsAddress() ? ($details['address'] ?? null) : null)
            ->setPostcode($mode->needsAddress() ? ($details['postcode'] ?? null) : null)
            ->setCity($mode->needsAddress() ? ($details['city'] ?? null) : null)
            ->setCountry($mode->needsAddress() ? ($details['country'] ?? null) : null)
            ->setNote($details['note'] ?? null);
        // An order paid before its hand-over was written (a payment taken at once): received already.
        if ($order->isPaid()) {
            $pickup->setStatus(PickupStatus::RECEIVED);
        }
        $this->entityManager->flush();
        $this->dispatcher->dispatch(new PickupChangedEvent($pickup));

        return $pickup;
    }

    /** Moves a hand-over along and tells the application (PickupChangedEvent); the same status again is nothing. */
    public function move(Pickup $pickup, PickupStatus $status, bool $flush = true): Pickup
    {
        $previous = $pickup->getStatus();
        if ($previous === $status) {
            return $pickup;
        }
        $pickup->setStatus($status);
        if ($flush) {
            $this->entityManager->flush();
        }
        $this->dispatcher->dispatch(new PickupChangedEvent($pickup, $previous));

        return $pickup;
    }

    /** The page that follows it, for whoever holds the link. */
    public function trackingUrl(Pickup $pickup, int $referenceType = UrlGeneratorInterface::ABSOLUTE_URL): string
    {
        return $this->router->generate('marketplace_pickup', ['token' => $pickup->getToken()], $referenceType);
    }
}
