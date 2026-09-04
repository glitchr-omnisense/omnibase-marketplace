<?php

namespace Base\Market\Repository\Sales\Attribute;

use Base\Market\Entity\Sales\Attribute\TaxScope;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method TaxScope|null find($id, $lockMode = null, $lockVersion = null)
 * @method TaxScope|null findOneBy(array $criteria, array $orderBy = null)
 * @method TaxScope[]    findAll()
 * @method TaxScope[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TaxScopeRepository extends ServiceEntityRepository
{
}
