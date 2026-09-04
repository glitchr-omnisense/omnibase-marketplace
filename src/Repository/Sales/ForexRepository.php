<?php

namespace Base\Market\Repository\Sales;

use Base\Market\Entity\Sales\Forex;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Forex|null find($id, $lockMode = null, $lockVersion = null)
 * @method Forex|null findOneBy(array $criteria, array $orderBy = null)
 * @method Forex[]    findAll()
 * @method Forex[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ForexRepository extends ServiceEntityRepository
{
}
