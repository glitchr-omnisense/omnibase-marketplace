<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Invoice\Transmission\Entity\InvoiceFlow;
use Doctrine\ORM\EntityManagerInterface;
use Omnibill\Email\StatusStoreInterface;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\StatusChange;

/**
 * Where omnibill/email keeps the statuses of the invoices it sent - nobody
 * else tells them: beside the invoices (Entity\InvoiceFlow), found by the
 * message's reference. A status recorded before the invoice knows its
 * reference (the e-mail being sent) is held until it does.
 *
 * The store is an object of an anonymous class, made by create(): no class
 * of this bundle implements an omnibill interface, so that loading any of
 * them (a compiler pass's class_exists() over every definition, excluded
 * ones included) never fails on a site without the family. Registered only
 * when omnibill/email is installed.
 */
final class InvoiceStatusStore
{
    public static function create(EntityManagerInterface $entityManager): StatusStoreInterface
    {
        return new class($entityManager) implements StatusStoreInterface {
            /** @var array<string, list<StatusChange>> */
            private array $pending = [];

            public function __construct(private readonly EntityManagerInterface $entityManager)
            {
            }

            public function record(string $reference, StatusChange $change): void
            {
                $row = $this->row($reference);
                if (null === $row) {
                    $this->pending[$reference][] = $change;

                    return;
                }
                $row->setHistory([...$row->getHistory(), InvoiceTransmission::row($change)]);
            }

            public function history(string $reference): array
            {
                $row = $this->row($reference);
                if (null === $row) {
                    return $this->pending[$reference] ?? [];
                }

                return array_values(array_filter(array_map(static fn (array $r) => null === ($status = LifecycleStatus::tryFrom($r['status'])) ? null : new StatusChange(
                    $status,
                    $row->getInvoice()->getNumber(),
                    null !== $r['at'] ? new \DateTimeImmutable($r['at']) : null,
                    $r['reason'] ?? null,
                ), $row->getHistory())));
            }

            private function row(string $reference): ?InvoiceFlow
            {
                return $this->entityManager->getRepository(InvoiceFlow::class)->findOneBy(['reference' => $reference]);
            }
        };
    }
}
