<?php

namespace Base\Marketplace\Catalogue;

use Base\Marketplace\Repository\Catalogue\PlatformLinkRepository;
use Omnitrade\Request\FetchInventory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The stock levels alone, more often than the whole catalogue: every linked
 * variant's count read from the platform (FetchInventory) and written here.
 * A platform that counts nothing (Stripe) is left alone: the stock stays
 * the site's.
 */
class InventorySynchronizer
{
    public function __construct(
        private readonly PlatformSynchronizer $products,
        private readonly PlatformLinkRepository $links,
        #[Autowire('%marketplace.catalogue.inventory%')] private readonly bool $enabled = true,
    ) {
    }

    /** Whether this gateway's stock is read at all. */
    public function reads(string $gatewayName): bool
    {
        return $this->enabled && $this->products->gateway($gatewayName)->supports(FetchInventory::class);
    }

    /** @return int how many products got a new count */
    public function sync(string $gatewayName, bool $dryRun = false): int
    {
        if (!$this->reads($gatewayName)) {
            return 0;
        }
        $references = array_map(fn ($link) => $link->getRemoteVariant(), $this->links->findBy(['gateway' => $gatewayName, 'orphaned' => false]));
        if (!$references) {
            return 0;
        }

        $updated = 0;
        foreach (array_chunk($references, 100) as $chunk) {
            foreach ($this->products->gateway($gatewayName)->fetchInventory($chunk) as $stock) {
                if (!$dryRun && $this->products->updateStock($gatewayName, $stock)) {
                    ++$updated;
                }
            }
        }
        if (!$dryRun) {
            $this->products->flush();
        }

        return $updated;
    }
}
