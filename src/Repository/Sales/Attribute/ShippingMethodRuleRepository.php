<?php

namespace Base\Market\Repository\Sales\Attribute;

use Base\Market\Entity\Sales\Attribute\ShippingMethodRule;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method ShippingMethodRule|null find($id, $lockMode = null, $lockVersion = null)
 * @method ShippingMethodRule|null findOneBy(array $criteria, array $orderBy = null)
 * @method ShippingMethodRule[]    findAll()
 * @method ShippingMethodRule[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShippingMethodRuleRepository extends ServiceEntityRepository
{
}
