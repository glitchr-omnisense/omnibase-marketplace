<?php

namespace Base\Marketplace\Repository\Order;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Pickup;
use Base\Marketplace\Enum\PickupStatus;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/**
 * The hand-overs: an order's, a tracking page's (by its token), a day's.
 *
 * @extends ServiceEntityRepository<Pickup>
 */
class PickupRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Pickup::class);
    }

    public function of(Order $order): ?Pickup
    {
        return null === $order->getId() ? null : $this->findOneBy(['order' => Key::of($order)]);
    }

    /** @return list<Pickup> the orders made together, oldest first */
    public function byToken(string $token): array
    {
        return $this->findBy(['token' => $token], ['id' => 'ASC']);
    }

    /**
     * What the shop has to hand over on a day, a slot after the other; the
     * carts whose payment never came are left out.
     *
     * @return list<Pickup>
     */
    public function onDay(\DateTimeImmutable $day): array
    {
        // The day is bound with the mapping's type (date_immutable), as the hand-written query bound it.
        return $this->findBy([
            'day' => $day->setTime(0, 0),
            Group::of(Parser::SEPARATOR_AND, Criterion::of('status', PickupStatus::CHECKOUT, Parser::OPTION_NOT_EQUAL)),
        ], ['slot' => 'ASC', 'id' => 'ASC']);
    }
}
