<?php

namespace Base\Marketplace\Wishlist;

use Base\Marketplace\Entity\Wishlist\Item;
use Base\Marketplace\Entity\Wishlist\Reservation;
use Base\Marketplace\Enum\ReservationStatus;
use Base\Marketplace\Enum\WishlistItemKind;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
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

        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->lock($item, LockMode::PESSIMISTIC_WRITE);
            $left = $item->getQuantity() - $this->held($item);
            if ($left < $quantity) {
                throw new WishlistException('wishlist.error.taken', ['{left}' => max(0, $left)]);
            }
            $reservation = new Reservation($item, $quantity, $name, $email, hash('sha256', $token), new \DateTimeImmutable(sprintf('+%d days', $this->days)));
            $reservation->setGiverReference($giverReference)->setMessage($message);
            $item->getReservations()->add($reservation);
            $this->entityManager->persist($reservation);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }

        return ['reservation' => $reservation, 'token' => $token];
    }

    public function find(string $token): ?Reservation
    {
        return $this->entityManager->getRepository(Reservation::class)->findOneBy(['tokenHash' => hash('sha256', $token)]);
    }

    /** By its author: the object goes back on the list. */
    public function cancel(string $token): ?Reservation
    {
        $reservation = $this->find($token);
        if ($reservation && $reservation->getStatus()->holds()) {
            $reservation->setStatus(ReservationStatus::CANCELLED);
            $this->entityManager->flush();
        }

        return $reservation;
    }

    /** Bought: it no longer lapses. */
    public function confirm(Reservation $reservation): Reservation
    {
        if (ReservationStatus::HELD === $reservation->getStatus()) {
            $reservation->setStatus(ReservationStatus::CONFIRMED);
            $this->entityManager->flush();
        }

        return $reservation;
    }

    /** The promises that lapsed, marked so; their objects are back on their lists. */
    public function expire(?\DateTimeImmutable $now = null): int
    {
        return (int) $this->entityManager->createQuery('UPDATE '.Reservation::class.' r SET r.status = :expired WHERE r.status = :held AND r.expiresAt <= :now')
            ->setParameter('expired', ReservationStatus::EXPIRED)->setParameter('held', ReservationStatus::HELD)->setParameter('now', $now ?? new \DateTimeImmutable())
            ->execute();
    }

    /** How many of the item are promised or bought, as the database has it now. */
    private function held(Item $item): int
    {
        return (int) $this->entityManager->createQuery('SELECT COALESCE(SUM(r.quantity), 0) FROM '.Reservation::class.' r WHERE r.item = :item AND (r.status = :confirmed OR (r.status = :held AND r.expiresAt > :now))')
            ->setParameter('item', $item)->setParameter('confirmed', ReservationStatus::CONFIRMED)->setParameter('held', ReservationStatus::HELD)->setParameter('now', new \DateTimeImmutable())
            ->getSingleScalarResult();
    }
}
