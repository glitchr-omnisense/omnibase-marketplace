<?php

namespace Base\Marketplace\Service;

use Base\Entity\User;
use Base\Marketplace\Entity\Credit;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Somebody's credits of one kind (Entity\Credit): credited by a pack bought
 * or a plan's allowance, spent one use at a time. The balance is what the
 * grants still valid gave, less everything spent - never below zero: what an
 * expired grant had left is lost, what was spent stays spent.
 */
class Credits
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function grant(User $holder, string $kind, int $quantity, ?string $reason = null, ?\DateTimeImmutable $expiresAt = null, ?string $orderReference = null): Credit
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('A grant is positive.');
        }
        $credit = (new Credit($holder, $kind, $quantity, $reason, $expiresAt))->setOrderReference($orderReference);
        $this->entityManager->persist($credit);
        $this->entityManager->flush();

        return $credit;
    }

    public function balance(User $holder, string $kind, ?\DateTimeImmutable $at = null): int
    {
        $at ??= new \DateTimeImmutable();
        $granted = 0;
        $expired = 0;
        $spent = 0;
        foreach ($this->entityManager->getRepository(Credit::class)->findBy(['holder' => $holder, 'kind' => $kind], ['id' => 'ASC']) as $line) {
            if (!$line->isGrant()) {
                $spent -= $line->getQuantity();
            } elseif (null !== $line->getExpiresAt() && $line->getExpiresAt() <= $at) {
                $expired += $line->getQuantity();
            } else {
                $granted += $line->getQuantity();
            }
        }
        // What was spent is taken from the lapsed grants first (they were the
        // oldest): only what they could not cover weighs on the valid ones.
        return max(0, $granted - max(0, $spent - $expired));
    }

    /**
     * Spends $quantity, once: the holder's row is locked while the balance is
     * read, so two uses cannot both take the last credit.
     *
     * @throws CreditException when the balance does not cover it
     */
    public function spend(User $holder, string $kind, int $quantity = 1, ?string $reason = null, ?string $resourceType = null, int|string|null $resourceId = null): Credit
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('A spending is positive.');
        }

        return $this->transactional(function () use ($holder, $kind, $quantity, $reason, $resourceType, $resourceId): Credit {
            if (null !== $holder->getId()) {
                $this->entityManager->lock($holder, LockMode::PESSIMISTIC_WRITE);
            }
            $balance = $this->balance($holder, $kind);
            if ($balance < $quantity) {
                throw new CreditException($kind, $quantity, $balance);
            }
            $credit = (new Credit($holder, $kind, -$quantity, $reason))->setResource($resourceType, $resourceId);
            $this->entityManager->persist($credit);
            $this->entityManager->flush();

            return $credit;
        });
    }

    public function has(User $holder, string $kind, int $quantity = 1): bool
    {
        return $this->balance($holder, $kind) >= $quantity;
    }

    /** Gives back a spending (an answer that failed): a grant of the same size, without expiry. */
    public function refund(Credit $spent, ?string $reason = null): ?Credit
    {
        return $spent->isGrant() ? null : $this->grant($spent->getHolder(), $spent->getKind(), -$spent->getQuantity(), $reason ?? 'refund');
    }

    /**
     * A transaction by hand, not wrapInTransaction(): that closes the
     * EntityManager on any exception, and "none left" is an answer the
     * application goes on from, not a failure.
     */
    private function transactional(callable $work): mixed
    {
        $this->entityManager->beginTransaction();
        try {
            $result = $work();
            $this->entityManager->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }
    }
}
