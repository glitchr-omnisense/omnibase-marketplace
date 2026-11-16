<?php

namespace Base\Marketplace\Repository;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;

/**
 * @method Invoice|null find($id, $lockMode = null, $lockVersion = null)
 * @method Invoice|null findOneBy(array $criteria, array $orderBy = null)
 * @method Invoice[]    findAll()
 * @method Invoice[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InvoiceRepository extends ServiceEntityRepository
{
    /** The last number taken in a series and a year: 0 before the first. */
    public function lastSequence(string $series, int $year): int
    {
        return (int) $this->maxOfSequenceBy(['series' => $series, 'year' => $year]);
    }

    /** The invoice of an order that stands - not cancelled by a credit note. */
    public function standingFor(Order $order): ?Invoice
    {
        return $this->findOneBy([
            'order' => Key::of($order),
            'type' => Invoice::TYPE_INVOICE,
            Group::of(Parser::SEPARATOR_AND, Criterion::of('state', Invoice::STATE_CANCELLED, Parser::OPTION_NOT_EQUAL)),
        ]);
    }

    /** @return Invoice[] an order's invoices and credit notes, oldest first */
    public function ofOrder(Order $order): array
    {
        return $this->findBy(['order' => $order], ['id' => 'ASC']);
    }
}
