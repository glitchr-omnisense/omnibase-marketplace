<?php

namespace Base\Market\Repository\Product;

use Base\Market\Entity\Product\Identifier;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Identifier|null find($id, $lockMode = null, $lockVersion = null)
 * @method Identifier|null findOneBy(array $criteria, array $orderBy = null)
 * @method Identifier[]    findAll()
 * @method Identifier[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IdentifierRepository extends ServiceEntityRepository
{
}
