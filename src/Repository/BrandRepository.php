<?php

namespace Base\Marketplace\Repository;

use Base\Marketplace\Entity\Brand;
use Base\Repository\ThreadRepository;

/**
 * @method Brand|null find($id, $lockMode = null, $lockVersion = null)
 * @method Brand|null findOneBy(array $criteria, array $orderBy = null)
 * @method Brand[]    findAll()
 * @method Brand[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BrandRepository extends ThreadRepository
{
    /** The brand of that name, in any of its translations' titles - or null. */
    public function findOneByName(string $name): ?Brand
    {
        $name = trim($name);
        foreach ($this->findAll() as $brand) {
            if (0 === strcasecmp((string) $brand->getTitle(), $name)) {
                return $brand;
            }
        }

        return null;
    }
}
