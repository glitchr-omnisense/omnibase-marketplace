<?php

namespace Base\Market\Shopify\Repository;

use Base\Market\Shopify\Entity\ProductLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductLink>
 */
class ProductLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductLink::class);
    }

    public function findOneByVariant(string $shop, string $variantGid): ?ProductLink
    {
        return $this->findOneBy(['shop' => $shop, 'variantGid' => $variantGid]);
    }

    /** The webhook route: inventory_levels/update knows only this id. */
    public function findOneByInventoryItem(string $shop, string $inventoryItemId): ?ProductLink
    {
        return $this->findOneBy(['shop' => $shop, 'inventoryItemId' => $inventoryItemId]);
    }

    /** @return ProductLink[] every link of one Shopify product */
    public function findByProductGid(string $shop, string $productGid): array
    {
        return $this->findBy(['shop' => $shop, 'productGid' => $productGid]);
    }

    /** The watermark an incremental sync resumes from. */
    public function lastSyncedAt(string $shop): ?\DateTimeInterface
    {
        $value = $this->createQueryBuilder('l')
            ->select('MAX(l.syncedAt)')
            ->andWhere('l.shop = :shop')->setParameter('shop', $shop)
            ->getQuery()->getSingleScalarResult();

        return $value ? new \DateTime((string) $value) : null;
    }
}
