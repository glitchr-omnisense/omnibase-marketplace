<?php

namespace Base\Market\Service;

use Base\Market\Entity\Sales\Region;
use Base\Market\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every order belongs to a sales region (taxes, shipping and currency rules
 * hang off it). A store that sells everywhere in one currency has no reason
 * to configure one, so: the store's first region, else one region per
 * currency, created the first time it is needed.
 */
class RegionResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%market.default_currency%')] private readonly string $defaultCurrency = 'EUR',
    ) {
    }

    public function for(?Store $store): Region
    {
        $region = $store?->getRegions()->first();
        if ($region instanceof Region) {
            return $region;
        }

        $currency = $store?->getCurrency() ?: $this->defaultCurrency;
        $slug = 'market-'.strtolower($currency);

        $region = $this->entityManager->getRepository(Region::class)->findOneBy(['slug' => $slug]);
        if (!$region) {
            $region = new Region();
            $region->setLabel($currency);
            $region->setSlug($slug);
            $region->setCurrency($currency);
            $region->setEnabled(true);
            $this->entityManager->persist($region);
        }

        return $region;
    }
}
