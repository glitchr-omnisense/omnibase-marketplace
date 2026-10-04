<?php

namespace Base\Marketplace\Service;

use Base\Entity\User;
use Base\Marketplace\Entity\Entitlement;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Subscription;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Usage;
use Base\Marketplace\Event\EntitlementGrantedEvent;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * What somebody may do, from the rights they hold (Entity\Entitlement).
 * A grant is read by its kind:
 *
 *   a switch   true in one active entitlement is enough: allows('export');
 *   a value    the highest-level entitlement's: value('commission');
 *   a ceiling  the largest among them: limit('guests') - 150 guests per event;
 *   a count    the sum of them, less what was used: remaining('events.major'),
 *              consume() takes one, release() gives it back.
 *
 * A resource narrows the question to the entitlements made for that one
 * thing (a course, an event) plus those made for none.
 */
class Entitlements
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * @param array<string, int|float|bool|string|null> $grants
     * @param array{type?: ?string, id?: int|string|null, product?: ?Product, order?: ?Order, subscription?: ?Subscription, level?: int, startsAt?: ?\DateTimeImmutable} $context
     */
    public function grant(User $holder, string $code, array $grants = [], ?\DateTimeImmutable $expiresAt = null, array $context = []): Entitlement
    {
        $entitlement = new Entitlement($holder, $code, $grants, $expiresAt, $context['startsAt'] ?? null);
        $entitlement->setResource($context['type'] ?? null, $context['id'] ?? null);
        $entitlement->setProduct($context['product'] ?? null);
        $entitlement->setOrderReference(isset($context['order']) ? (string) $context['order']->getReference() : null);
        $entitlement->setSubscription($context['subscription'] ?? null);
        $entitlement->setLevel((int) ($context['level'] ?? 0));
        $this->entityManager->persist($entitlement);
        $this->entityManager->flush();
        $this->dispatcher?->dispatch(new EntitlementGrantedEvent($entitlement, $context['order'] ?? null));

        return $entitlement;
    }

    /**
     * The rights held now, the highest level first.
     *
     * @return list<Entitlement>
     */
    public function active(User $holder, ?string $resourceType = null, int|string|null $resourceId = null, ?\DateTimeImmutable $at = null): array
    {
        $all = $this->entityManager->getRepository(Entitlement::class)->findBy(['holder' => $holder]);
        $active = array_values(array_filter($all, static fn (Entitlement $e) => $e->isActive($at)
            && (null === $e->getResourceType() || $e->isFor($resourceType, $resourceId))));
        usort($active, static fn (Entitlement $a, Entitlement $b) => [$b->getLevel(), $b->getId()] <=> [$a->getLevel(), $a->getId()]);

        return $active;
    }

    /** Whether they hold an entitlement of that code (for that resource): the right that is its own grant. */
    public function holds(User $holder, string $code, ?string $resourceType = null, int|string|null $resourceId = null): bool
    {
        foreach ($this->active($holder, $resourceType, $resourceId) as $entitlement) {
            if ($entitlement->getCode() === $code && $entitlement->isFor($resourceType, $resourceId)) {
                return true;
            }
        }

        return false;
    }

    /** A switch is on, or a count has some left. */
    public function allows(User $holder, string $key, ?string $resourceType = null, int|string|null $resourceId = null): bool
    {
        foreach ($this->active($holder, $resourceType, $resourceId) as $entitlement) {
            $grant = $entitlement->getGrant($key);
            if (true === $grant || (\is_string($grant) && '' !== $grant) || (\is_float($grant) && $grant > 0)) {
                return true;
            }
            if (\is_int($grant) && $grant > 0 && $this->left($entitlement, $key) > 0) {
                return true;
            }
        }

        return false;
    }

    /** The grant as the highest-level entitlement that has it says. */
    public function value(User $holder, string $key, mixed $default = null, ?string $resourceType = null, int|string|null $resourceId = null): mixed
    {
        foreach ($this->active($holder, $resourceType, $resourceId) as $entitlement) {
            if (null !== $entitlement->getGrant($key)) {
                return $entitlement->getGrant($key);
            }
        }

        return $default;
    }

    /** A ceiling: the largest among the active entitlements; null when none sets one. */
    public function limit(User $holder, string $key, ?string $resourceType = null, int|string|null $resourceId = null): ?int
    {
        $limit = null;
        foreach ($this->active($holder, $resourceType, $resourceId) as $entitlement) {
            $grant = $entitlement->getGrant($key);
            if (\is_int($grant) || \is_float($grant)) {
                $limit = max($limit ?? 0, (int) $grant);
            }
        }

        return $limit;
    }

    /** A count: what all the active entitlements grant, less what was used. */
    public function remaining(User $holder, string $key, ?string $resourceType = null, int|string|null $resourceId = null): int
    {
        $left = 0;
        foreach ($this->active($holder, $resourceType, $resourceId) as $entitlement) {
            $left += $this->left($entitlement, $key);
        }

        return $left;
    }

    /**
     * Takes $quantity of a counted grant, from one entitlement that has as
     * much left; what took it is named ($usedByType, $usedById) so release()
     * finds it again. The entitlement's row is locked while counting: two
     * requests cannot take the last one both.
     *
     * @throws EntitlementException when none has as much left
     */
    public function consume(User $holder, string $key, int $quantity = 1, ?string $usedByType = null, int|string|null $usedById = null): Usage
    {
        return $this->transactional(function () use ($holder, $key, $quantity, $usedByType, $usedById): Usage {
            // The soonest to end first: what would be lost is spent first.
            $candidates = $this->active($holder);
            usort($candidates, static fn (Entitlement $a, Entitlement $b) => ($a->getExpiresAt()?->getTimestamp() ?? \PHP_INT_MAX) <=> ($b->getExpiresAt()?->getTimestamp() ?? \PHP_INT_MAX));
            foreach ($candidates as $entitlement) {
                if (!\is_int($entitlement->getGrant($key))) {
                    continue;
                }
                $this->entityManager->lock($entitlement, LockMode::PESSIMISTIC_WRITE);
                if ($this->left($entitlement, $key) >= $quantity) {
                    $usage = new Usage($entitlement, $key, $quantity, $usedByType, $usedById);
                    $this->entityManager->persist($usage);
                    $this->entityManager->flush();

                    return $usage;
                }
            }

            throw new EntitlementException($key);
        });
    }

    /** Gives back what that thing had taken of a counted grant. */
    public function release(User $holder, string $key, ?string $usedByType, int|string|null $usedById): int
    {
        $released = 0;
        foreach ($this->usages($holder, $key) as $usage) {
            if (!$usage->isReleased() && $usage->getResourceType() === $usedByType && $usage->getResourceId() === (null === $usedById ? null : (string) $usedById)) {
                $usage->release();
                $released += $usage->getQuantity();
            }
        }
        $this->entityManager->flush();

        return $released;
    }

    /** The entitlement a thing took a counted grant from (the plan that covers an event), if any. */
    public function coveredBy(User $holder, string $key, ?string $usedByType, int|string|null $usedById): ?Entitlement
    {
        foreach ($this->usages($holder, $key) as $usage) {
            if (!$usage->isReleased() && $usage->getResourceType() === $usedByType && $usage->getResourceId() === (null === $usedById ? null : (string) $usedById)) {
                return $usage->getEntitlement();
            }
        }

        return null;
    }

    /** What one entitlement has left of a counted grant. */
    public function left(Entitlement $entitlement, string $key): int
    {
        $grant = $entitlement->getGrant($key);
        if (!\is_int($grant)) {
            return 0;
        }
        if (null === $entitlement->getId()) {
            return $grant;
        }
        $used = (int) $this->entityManager->createQuery('SELECT COALESCE(SUM(u.quantity), 0) FROM '.Usage::class.' u WHERE u.entitlement = :e AND u.key = :k AND u.releasedAt IS NULL')
            ->setParameter('e', $entitlement)->setParameter('k', $key)->getSingleScalarResult();

        return max(0, $grant - $used);
    }

    /** @return list<Usage> */
    private function usages(User $holder, string $key): array
    {
        return $this->entityManager->createQuery('SELECT u FROM '.Usage::class.' u JOIN u.entitlement e WHERE e.holder = :h AND u.key = :k ORDER BY u.id ASC')
            ->setParameter('h', $holder)->setParameter('k', $key)->getResult();
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
