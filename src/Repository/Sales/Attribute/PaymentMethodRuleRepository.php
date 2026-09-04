<?php

namespace Base\Market\Repository\Sales\Attribute;

use Base\Market\Entity\Sales\Attribute\PaymentMethodRule;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method PaymentMethodRule|null find($id, $lockMode = null, $lockVersion = null)
 * @method PaymentMethodRule|null findOneBy(array $criteria, array $orderBy = null)
 * @method PaymentMethodRule[]    findAll()
 * @method PaymentMethodRule[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PaymentMethodRuleRepository extends ServiceEntityRepository
{
}
