<?php

namespace Base\Marketplace\Repository\Product;

use Base\Marketplace\Entity\Product\Taxon;

/**
 * @method Taxon|null find($id, $lockMode = null, $lockVersion = null)
 * @method Taxon|null findOneBy(array $criteria, array $orderBy = null)
 * @method Taxon[]    findAll()
 * @method Taxon[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TaxonRepository extends \Base\Repository\Thread\TaxonRepository
{
}
