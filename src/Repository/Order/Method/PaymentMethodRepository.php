<?php

namespace Base\Market\Repository\Order\Method;

use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method PaymentMethod|null find($id, $lockMode = null, $lockVersion = null)
 * @method PaymentMethod|null findOneBy(array $criteria, array $orderBy = null)
 * @method PaymentMethod[]    findAll()
 * @method PaymentMethod[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PaymentMethodRepository extends ServiceEntityRepository
{
}
