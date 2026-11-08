<?php

namespace Base\Marketplace\Repository\Sales\Attribute;

use Base\Marketplace\Entity\Sales\Attribute\ShippingMethodScope;
use Omnibridge\Doctrine\Omnibase\Database\Repository\ServiceEntityRepository;

/**
 * @method ShippingMethodScope|null find($id, $lockMode = null, $lockVersion = null)
 * @method ShippingMethodScope|null findOneBy(array $criteria, array $orderBy = null)
 * @method ShippingMethodScope[]    findAll()
 * @method ShippingMethodScope[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShippingMethodScopeRepository extends ServiceEntityRepository
{
}
