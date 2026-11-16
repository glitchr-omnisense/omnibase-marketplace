<?php

namespace Base\Marketplace\Repository\Catalogue;

use Base\Marketplace\Entity\Catalogue\PlatformLink;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<PlatformLink> */
class PlatformLinkRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, PlatformLink::class);
    }

    public function findVariant(string $gateway, string $remoteVariant): ?PlatformLink
    {
        return $this->findOneBy(['gateway' => $gateway, 'remoteVariant' => $remoteVariant]);
    }

    /** @return list<PlatformLink> */
    public function findProduct(string $gateway, string $remoteProduct): array
    {
        return $this->findBy(['gateway' => $gateway, 'remoteProduct' => $remoteProduct]);
    }

    public function findItem(string $gateway, string $remoteItem): ?PlatformLink
    {
        return $this->findOneBy(['gateway' => $gateway, 'remoteItem' => $remoteItem]);
    }

    /** The newest change read from this platform: where an incremental sync starts. */
    public function lastRemoteUpdate(string $gateway): ?\DateTimeImmutable
    {
        $value = $this->maxOfRemoteUpdatedAtBy(['gateway' => $gateway]);

        return $value ? new \DateTimeImmutable((string) $value) : null;
    }
}
