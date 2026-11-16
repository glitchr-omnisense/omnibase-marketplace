<?php

namespace Base\Marketplace\Repository\Sales;

use Base\Marketplace\Entity\Sales\Fee;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Fee|null find($id, $lockMode = null, $lockVersion = null)
 * @method Fee|null findOneBy(array $criteria, array $orderBy = null)
 * @method Fee[]    findAll()
 * @method Fee[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FeeRepository extends ServiceEntityRepository
{
}
