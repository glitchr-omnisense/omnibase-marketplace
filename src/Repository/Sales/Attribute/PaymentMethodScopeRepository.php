<?php

namespace Base\Market\Repository\Sales\Attribute;

use Base\Market\Entity\Sales\Attribute\PaymentMethodScope;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method PaymentMethodScope|null find($id, $lockMode = null, $lockVersion = null)
 * @method PaymentMethodScope|null findOneBy(array $criteria, array $orderBy = null)
 * @method PaymentMethodScope[]    findAll()
 * @method PaymentMethodScope[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PaymentMethodScopeRepository extends ServiceEntityRepository
{
}
