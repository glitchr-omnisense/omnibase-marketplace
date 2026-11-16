<?php

namespace Base\Marketplace\Wishlist;

use Base\Marketplace\Entity\Wishlist\Item;
use Base\Marketplace\Entity\Wishlist\Reservation;
use Base\Marketplace\Enum\ReservationStatus;
use Base\Marketplace\Enum\WishlistItemKind;
use Base\Database\Attribute\Backend\Backends;
use Base\Database\Backend\RowLocks;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\UnitOfWorks;
use Base\Marketplace\Repository\Wishlist\ReservationRepository;
use Base\Contract\UnitOfWork;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An object promised leaves the list for the others. The item's row is
 * locked while what is left is counted - from the database, not from
 * memory -, so two givers cannot both take the last one. A reservation is
 * cancelled with the token handed to its author (only its hash is kept),
 * and lapses after marketplace.wishlist.reservation_days unless confirmed.
 */
class Reservations
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        #[Autowire('%marketplace.wishlist.reservation_days%')] private readonly int $days = 30,
    ) {
    }

    /**
     * @return array{reservation: Reservation, token: string} the token cancels it: send it to its author
     *
     * @throws WishlistException wishlist.error.closed, .not_reservable, .taken
     */
    public function reserve(Item $item, int $quantity, string $name, ?string $email = null, ?string $giverReference = null, ?string $message = null): array
    {
        if (true !== $item->getWishlist()?->isOpen() || $item->isHidden()) {
            throw new WishlistException('wishlist.error.closed');
        }
        if (WishlistItemKind::OBJECT !== $item->getKind()) {
            throw new WishlistException('wishlist.error.not_reservable');
        }
        $quantity = max(1, $quantity);
        $token = bin2hex(random_bytes(24));

        // The item's row locked while what is left is read: two givers cannot take the last one both.
        $reservation = Backends::of($this->unitOfWork, RowLocks::class)->locked($item, function () use ($item, $quantity, $name, $email, $token, $giverReference, $message): Reservation {
            $left = $item->getQuantity() - $this->held($item);
            if ($left < $quantity) {
                throw new WishlistException('wishlist.error.taken', ['{left}' => max(0, $left)]);
            }
            $reservation = new Reservation($item, $quantity, $name, $email, hash('sha256', $token), new \DateTimeImmutable(sprintf('+%d days', $this->days)));
            $reservation->setGiverReference($giverReference)->setMessage($message);
            $item->getReservations()->add($reservation);
            $this->unitOfWork->persist($reservation);
            $this->unitOfWork->flush();

            return $reservation;
        });

        return ['reservation' => $reservation, 'token' => $token];
    }

    public function find(string $token): ?Reservation
    {
        return $this->repository()->findOneBy(['tokenHash' => hash('sha256', $token)]);
    }

    /** By its author: the object goes back on the list. */
    public function cancel(string $token): ?Reservation
    {
        $reservation = $this->find($token);
        if ($reservation && $reservation->getStatus()->holds()) {
            $reservation->setStatus(ReservationStatus::CANCELLED);
            $this->unitOfWork->flush();
        }

        return $reservation;
    }

    /** Bought: it no longer lapses. */
    public function confirm(Reservation $reservation): Reservation
    {
        if (ReservationStatus::HELD === $reservation->getStatus()) {
            $reservation->setStatus(ReservationStatus::CONFIRMED);
            $this->unitOfWork->flush();
        }

        return $reservation;
    }

    /** The promises that lapsed, marked so; their objects are back on their lists. */
    public function expire(?\DateTimeImmutable $now = null): int
    {
        return $this->repository()->expire($now ?? new \DateTimeImmutable());
    }

    /** How many of the item are promised or bought, as the database has it now. */
    private function held(Item $item): int
    {
        return (int) $this->repository()->sumOfQuantityBy([
            'item' => Key::of($item),
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('status', ReservationStatus::CONFIRMED),
                Group::of(Parser::SEPARATOR_AND,
                    Criterion::of('status', ReservationStatus::HELD),
                    Criterion::of('expiresAt', new \DateTimeImmutable(), Parser::OPTION_GREATER),
                ),
            ),
        ]);
    }

    private function repository(): ReservationRepository
    {
        return UnitOfWorks::repositories($this->unitOfWork)->of(Reservation::class);
    }
}
