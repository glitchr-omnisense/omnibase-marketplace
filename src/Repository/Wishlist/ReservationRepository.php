<?php

namespace Base\Marketplace\Repository\Wishlist;

use Base\Marketplace\Enum\ReservationStatus;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Criterion;
use Base\Marketplace\Entity\Wishlist\Reservation;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * The givers' promises on a list's objects (Wishlist\Reservations).
 *
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Reservation::class);
    }

    /** The promises that lapsed by $now, marked expired at once: how many. */
    public function expire(\DateTimeImmutable $now): int
    {
        return $this->updateBy([
            'status' => ReservationStatus::HELD->value,
            Group::of(Parser::SEPARATOR_AND, Criterion::of('expiresAt', $now, Parser::OPTION_LOWER_EQUAL)),
        ], ['status' => ReservationStatus::EXPIRED->value]);
    }
}
