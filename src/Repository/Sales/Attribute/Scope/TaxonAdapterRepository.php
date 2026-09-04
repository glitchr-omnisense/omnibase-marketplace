<?php

namespace Base\Market\Repository\Sales\Attribute\Scope;

use Base\Market\Entity\Sales\Attribute\Scope\TaxonAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method TaxonAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method TaxonAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method TaxonAdapter[]    findAll()
 * @method TaxonAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TaxonAdapterRepository extends ServiceEntityRepository
{
}
