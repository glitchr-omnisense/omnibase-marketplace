<?php

namespace Base\Marketplace\Repository;

use Base\Database\Repository\ServiceEntityRepository;
use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;

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
        return (int) $this->createQueryBuilder('i')
            ->select('MAX(i.sequence)')
            ->where('i.series = :series')->setParameter('series', $series)
            ->andWhere('i.year = :year')->setParameter('year', $year)
            ->getQuery()->getSingleScalarResult();
    }

    /** The invoice of an order that stands - not cancelled by a credit note. */
    public function standingFor(Order $order): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->where('i.order = :order')->setParameter('order', $order)
            ->andWhere('i.type = :type')->setParameter('type', Invoice::TYPE_INVOICE)
            ->andWhere('i.state != :cancelled')->setParameter('cancelled', Invoice::STATE_CANCELLED)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return Invoice[] an order's invoices and credit notes, oldest first */
    public function ofOrder(Order $order): array
    {
        return $this->findBy(['order' => $order], ['id' => 'ASC']);
    }
}
