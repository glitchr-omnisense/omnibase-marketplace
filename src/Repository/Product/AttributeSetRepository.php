<?php

namespace Base\Marketplace\Repository\Product;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\AttributeSet;
use Base\Marketplace\Entity\Product\Taxon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AttributeSet> */
class AttributeSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttributeSet::class);
    }

    /** The set of a taxon: its own, else its parent's, up the tree, else the set of every product. */
    public function forTaxon(?Taxon $taxon): ?AttributeSet
    {
        $seen = [];
        while ($taxon instanceof Taxon && !isset($seen[spl_object_id($taxon)])) {
            $seen[spl_object_id($taxon)] = true;
            if ($set = $this->findOneBy(['taxon' => $taxon])) {
                return $set;
            }
            $parent = $taxon->getParent();
            $taxon = $parent instanceof Taxon ? $parent : null;
        }

        return $this->findOneBy(['taxon' => null]);
    }

    /** The set a product is described by: its first taxon's that has one. */
    public function forProduct(Product $product): ?AttributeSet
    {
        foreach ($product->getTaxa() as $taxon) {
            if ($taxon instanceof Taxon && ($set = $this->forTaxon($taxon)) && $set->getTaxon()) {
                return $set;
            }
        }

        return $this->findOneBy(['taxon' => null]);
    }
}
