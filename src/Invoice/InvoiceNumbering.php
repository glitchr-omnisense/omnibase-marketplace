<?php

namespace Base\Marketplace\Invoice;

use Base\Marketplace\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * The numbers of the invoices: a sequence without a gap per series and year
 * - F-2026-000001, F-2026-000002... - as the law asks (a chronological and
 * continuous sequence, CGI ann. II art. 242 nonies A; series are allowed).
 *
 * A number is taken and the invoice that bears it stored in one go, under a
 * lock of the series (symfony/lock, `marketplace.invoice.<series>`) and in a
 * transaction: two invoices issued at the same moment, from two requests or
 * two workers, never get the same number, and a number is never taken
 * without its invoice being written - a failure rolls both back. The table's
 * unique key (series, year, sequence) holds should the lock be missing.
 */
final class InvoiceNumbering
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LockFactory $locks = null,
        #[Autowire('%marketplace.invoice.digits%')] private readonly int $digits = 6,
    ) {
    }

    public function format(string $series, int $year, int $sequence): string
    {
        return sprintf('%s-%d-%s', $series, $year, str_pad((string) $sequence, $this->digits, '0', \STR_PAD_LEFT));
    }

    /**
     * The next number of the series, given to the invoice $make builds with it, stored at once.
     *
     * @param callable(int $sequence, string $number, int $year): Invoice $make
     */
    public function issue(string $series, \DateTimeImmutable $issuedAt, callable $make): Invoice
    {
        $year = (int) $issuedAt->format('Y');
        $lock = $this->locks?->createLock('marketplace.invoice.'.$series, 30);
        $lock?->acquire(true);
        try {
            $this->entityManager->beginTransaction();
            try {
                $sequence = $this->entityManager->getRepository(Invoice::class)->lastSequence($series, $year) + 1;
                $invoice = $make($sequence, $this->format($series, $year, $sequence), $year);
                $this->entityManager->persist($invoice);
                $this->entityManager->flush();
                $this->entityManager->commit();
            } catch (\Throwable $e) {
                $this->entityManager->rollback();

                throw $e;
            }
        } finally {
            $lock?->release();
        }

        return $invoice;
    }
}
