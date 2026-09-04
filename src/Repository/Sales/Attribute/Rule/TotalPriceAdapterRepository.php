<?php

namespace Base\Market\Repository\Sales\Attribute\Rule;

use Base\Market\Entity\Sales\Attribute\Rule\TotalPriceAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method TotalPriceAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method TotalPriceAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method TotalPriceAdapter[]    findAll()
 * @method TotalPriceAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TotalPriceAdapterRepository extends ServiceEntityRepository
{
}
