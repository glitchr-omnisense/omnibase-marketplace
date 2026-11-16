<?php

namespace Base\Marketplace\Repository\Order\Method;

use Base\Marketplace\Entity\Order\Method\ShippingMethod;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method ShippingMethod|null find($id, $lockMode = null, $lockVersion = null)
 * @method ShippingMethod|null findOneBy(array $criteria, array $orderBy = null)
 * @method ShippingMethod[]    findAll()
 * @method ShippingMethod[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShippingMethodRepository extends ServiceEntityRepository
{
}
