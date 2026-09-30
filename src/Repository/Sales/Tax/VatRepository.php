<?php

namespace Base\Marketplace\Repository\Sales\Tax;

use Base\Marketplace\Entity\Sales\Tax;
use Base\Marketplace\Repository\Sales\TaxRepository;

/**
 * @method Tax|null find($id, $lockMode = null, $lockVersion = null)
 * @method Tax|null findOneBy(array $criteria, array $orderBy = null)
 * @method Tax[]    findAll()
 * @method Tax[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class VatRepository extends TaxRepository
{
}
