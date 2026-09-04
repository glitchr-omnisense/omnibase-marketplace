<?php

namespace Base\Market\Repository\Product\Attribute;

use Base\Market\Entity\Product\Attribute\Hyperlink;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Hyperlink|null find($id, $lockMode = null, $lockVersion = null)
 * @method Hyperlink|null findOneBy(array $criteria, array $orderBy = null)
 * @method Hyperlink[]    findAll()
 * @method Hyperlink[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HyperlinkRepository extends ServiceEntityRepository
{
}
