<?php

namespace Base\Marketplace\Quote;

use Base\Entity\User;
use Base\Marketplace\Entity\Quote\AbstractQuote;
use Base\Marketplace\Enum\QuoteStatus;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;

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
    /** The prefix of the quotes' numbers (Q-2026-0042). */
    public function quotePrefix(): string
    {
        return 'Q';
    }

    /**
     * Numbers a new quote <PREFIX>-<year>-<n> and saves it, in one transaction.
     *
     * n is one more than the year's highest, read with its row locked (SELECT ... FOR UPDATE, lockOneBy()): two
     * quotes asked for at the same moment wait for each other instead of taking the same number, and a quote deleted
     * leaves a gap rather than a number taken twice.
     *
     * A quote that has its reference already is only saved.
     */
    public function saveNumbered(AbstractQuote $quote, ?\DateTimeImmutable $at = null): void
    {
        $this->unitOfWork()->transactional(function () use ($quote, $at): void {
            if ('' === $quote->getReference()) {
                $prefix = $this->quotePrefix().'-'.($at ?? new \DateTimeImmutable())->format('Y').'-';
                $last = $this->lockOneBy([Group::of(Parser::SEPARATOR_AND, Criterion::of('reference', $prefix, Parser::OPTION_STARTING_WITH))], ['reference' => 'DESC']);
                $number = $last instanceof AbstractQuote ? (int) substr($last->getReference(), \strlen($prefix)) : 0;
                $quote->setReference(sprintf('%s%04d', $prefix, $number + 1));
            }
            $this->unitOfWork()->persist($quote);
        });
    }

    /** @return list<AbstractQuote> the client's, or those sent to their e-mail */
    public function findForClient(User $user): array
    {
        return $this->findBy([
            Group::of(Parser::SEPARATOR_OR,
                Criterion::of('client', Key::of($user)),
                Criterion::of('email', (string) $user->getEmail()),
            ),
        ], ['createdAt' => 'DESC']);
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
