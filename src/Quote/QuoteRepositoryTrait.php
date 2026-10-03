<?php

namespace Base\Marketplace\Quote;

use Base\Entity\User;
use Base\Marketplace\Entity\Quote\AbstractQuote;
use Base\Marketplace\Enum\QuoteStatus;
use Doctrine\DBAL\LockMode;

/**
 * What every quote table answers, for a ServiceEntityRepository of quotes:
 * numbered when saved, a client's quotes, the pipeline's columns. A trait
 * rather than a parent class: omnibase registers every class of a bundle's
 * src/Repository as a repository service of its own, an abstract one too.
 *
 * The using repository says its prefix with quotePrefix() (Q-2026-0042).
 */
trait QuoteRepositoryTrait
{
    protected function quotePrefix(): string
    {
        return 'Q';
    }

    /**
     * Numbers a new quote <PREFIX>-<year>-<n> and saves it, in one transaction.
     *
     * n is one more than the year's highest, read with its rows locked (SELECT
     * ... FOR UPDATE): two quotes asked for at the same moment wait for each
     * other instead of taking the same number, and a quote deleted leaves a
     * gap rather than a number taken twice.
     *
     * A quote that has its reference already is only saved.
     */
    public function saveNumbered(AbstractQuote $quote, ?\DateTimeImmutable $at = null): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->beginTransaction();
        try {
            if ('' === $quote->getReference()) {
                $prefix = $this->quotePrefix().'-'.($at ?? new \DateTimeImmutable())->format('Y').'-';
                $last = $this->createQueryBuilder('q')
                    ->select('q.reference')
                    ->andWhere('q.reference LIKE :prefix')->setParameter('prefix', $prefix.'%')
                    ->orderBy('q.reference', 'DESC')
                    ->setMaxResults(1)
                    ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
                $number = $last ? (int) substr((string) $last['reference'], \strlen($prefix)) : 0;
                $quote->setReference(sprintf('%s%04d', $prefix, $number + 1));
            }
            $entityManager->persist($quote);
            $entityManager->flush();
            $entityManager->commit();
        } catch (\Throwable $e) {
            $entityManager->rollback();

            throw $e;
        }
    }

    /** @return list<AbstractQuote> the client's, or those sent to their e-mail */
    public function findForClient(User $user): array
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.client = :user OR q.email = :email')
            ->setParameter('user', $user)->setParameter('email', (string) $user->getEmail())
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return array<string, list<AbstractQuote>> the quotes by status, in the pipeline's order */
    public function pipeline(): array
    {
        $columns = [];
        foreach (QuoteStatus::pipeline() as $status) {
            $columns[$status->value] = [];
        }
        foreach ($this->findBy([], ['createdAt' => 'DESC']) as $quote) {
            $columns[$quote->getStatus()->value][] = $quote;
        }

        return $columns;
    }
}
