<?php

namespace Base\Market\Repository\Sales\Attribute;

use Base\Market\Entity\Sales\Attribute\DiscountAction;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method DiscountAction|null find($id, $lockMode = null, $lockVersion = null)
 * @method DiscountAction|null findOneBy(array $criteria, array $orderBy = null)
 * @method DiscountAction[]    findAll()
 * @method DiscountAction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DiscountActionRepository extends ServiceEntityRepository
{
}
