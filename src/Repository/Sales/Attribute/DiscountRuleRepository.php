<?php

namespace Base\Market\Repository\Sales\Attribute;

use Base\Market\Entity\Sales\Attribute\DiscountRule;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method DiscountRule|null find($id, $lockMode = null, $lockVersion = null)
 * @method DiscountRule|null findOneBy(array $criteria, array $orderBy = null)
 * @method DiscountRule[]    findAll()
 * @method DiscountRule[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DiscountRuleRepository extends ServiceEntityRepository
{
}
