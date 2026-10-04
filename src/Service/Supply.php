<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\SupplyJob;
use Base\Marketplace\Enum\SupplyStatus;
use Base\Marketplace\Event\SupplyJobChangedEvent;
use Base\Marketplace\Supply\Model\SupplyLine;
use Base\Marketplace\Supply\Model\SupplyOrder;
use Base\Marketplace\Supply\Model\SupplyRecipient;
use Base\Marketplace\Supply\Model\SupplyResult;
use Base\Marketplace\Supply\SupplierRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * What is made to order, handed to who makes it. Once an order is paid, its
 * lines whose product names a supplier are grouped by supplier and by
 * recipient - each line may have its own (OrderItem::$recipient: a card
 * sent to each guest), else the order's shipping address - and each group
 * is one SupplyJob submitted to its supplier. The jobs then follow what the
 * suppliers say (their webhook, a workshop's page, `sync()`).
 *
 * A supplier that fails does not undo the payment: its job is kept FAILED
 * with the reason, to be sent again (`resubmit()`).
 */
class Supply
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SupplierRegistry $suppliers,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The jobs of an order, made and submitted; none when nothing in it is made to order, or when they exist already.
     *
     * @return list<SupplyJob>
     */
    public function dispatch(Order $order, bool $draft = false): array
    {
        if ($this->jobsOf($order)) {
            return [];
        }
        $groups = [];
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $supplier = $product?->getSupplier();
            if (null === $supplier || '' === $supplier) {
                continue;
            }
            $recipient = $this->recipient($order, $item->getRecipient());
            $personalisation = $item->getPersonalisation() ?? [];
            $key = $supplier.'#'.($recipient?->key() ?? 'none');
            $groups[$key] ??= ['supplier' => $supplier, 'recipient' => $recipient, 'lines' => []];
            $groups[$key]['lines'][] = [
                'item' => $item->getId() ?? spl_object_id($item),
                'product' => (string) ($product->getSupplierReference() ?: $product->getSlug()),
                'quantity' => max(1, (int) $item->getQuantity()),
                'title' => (string) $product,
                'files' => array_values(array_filter((array) ($personalisation['files'] ?? []), 'is_string')),
                'options' => array_diff_key($personalisation, ['files' => true]),
            ];
        }

        $jobs = [];
        $rank = 0;
        foreach ($groups as $group) {
            $job = new SupplyJob($order, $group['supplier'], sprintf('%s-%d', $order->getReference() ?? $order->getId(), ++$rank), $group['lines'], $group['recipient']?->toArray() ?? []);
            $this->entityManager->persist($job);
            $this->submit($job, $draft);
            $jobs[] = $job;
        }
        $this->entityManager->flush();

        return $jobs;
    }

    /** A job that failed, sent again. */
    public function resubmit(SupplyJob $job, bool $draft = false): SupplyJob
    {
        if (SupplyStatus::FAILED === $job->getStatus()) {
            $this->submit($job, $draft);
            $this->entityManager->flush();
        }

        return $job;
    }

    /** A draft launched. */
    public function confirm(SupplyJob $job): SupplyJob
    {
        if (SupplyStatus::DRAFT === $job->getStatus() && null !== $job->getSupplierReference()) {
            $this->attempt($job, fn ($supplier) => $supplier->confirm($job->getSupplierReference()));
            $this->entityManager->flush();
        }

        return $job;
    }

    /** Asks its supplier where it stands. */
    public function sync(SupplyJob $job): SupplyJob
    {
        if (null !== $job->getSupplierReference() && !$job->getStatus()->isFinal()) {
            $this->attempt($job, fn ($supplier) => $supplier->fetch($job->getSupplierReference()), false);
            $this->entityManager->flush();
        }

        return $job;
    }

    public function cancel(SupplyJob $job): SupplyJob
    {
        if (null !== $job->getSupplierReference() && !$job->getStatus()->isFinal()) {
            $this->attempt($job, fn ($supplier) => $supplier->cancel($job->getSupplierReference()), false);
            $this->entityManager->flush();
        }

        return $job;
    }

    /** A supplier's word (its webhook, its page): applied to the job it is about, when we know it. */
    public function apply(SupplyResult $result): ?SupplyJob
    {
        $jobs = $this->entityManager->getRepository(SupplyJob::class);
        $job = $jobs->findOneBy(['supplier' => $result->supplier, 'supplierReference' => $result->reference])
            ?? (null !== $result->orderReference ? $jobs->findOneBy(['supplier' => $result->supplier, 'reference' => $result->orderReference]) : null);
        if ($job) {
            $this->record($job, $result);
            $this->entityManager->flush();
        }

        return $job;
    }

    /** @return list<SupplyJob> */
    public function jobsOf(Order $order): array
    {
        return null === $order->getId() ? [] : $this->entityManager->getRepository(SupplyJob::class)->findBy(['order' => $order], ['id' => 'ASC']);
    }

    public function find(string $reference): ?SupplyJob
    {
        return $this->entityManager->getRepository(SupplyJob::class)->findOneBy(['reference' => $reference]);
    }

    private function submit(SupplyJob $job, bool $draft): void
    {
        $recipient = SupplyRecipient::fromArray($job->getRecipient());
        if (!$recipient->isComplete()) {
            $job->update(SupplyStatus::FAILED, message: 'No complete recipient address.');

            return;
        }
        $lines = array_map(static fn (array $l) => new SupplyLine((string) $l['item'], (string) $l['product'], (int) $l['quantity'], (array) $l['files'], $l['title'] ?? null, (array) ($l['options'] ?? [])), $job->getLines());
        $order = $job->getOrder();
        $supply = new SupplyOrder($job->getReference(), $lines, $recipient, strtoupper((string) ($order->getCurrency() ?: 'EUR')), null, (string) $order->getCustomer()?->getId(), ['order' => (string) $order->getReference()]);
        $this->attempt($job, static fn ($supplier) => $supplier->submit($supply, $draft));
    }

    /** One call to the job's supplier, its answer recorded; a failure is kept on the job (or only logged). */
    private function attempt(SupplyJob $job, callable $call, bool $failJob = true): void
    {
        $supplier = $this->suppliers->get($job->getSupplier());
        try {
            if (null === $supplier || !$supplier->isConfigured()) {
                throw new \RuntimeException(sprintf('The supplier "%s" is not configured.', $job->getSupplier()));
            }
            $this->record($job, $call($supplier));
        } catch (\Throwable $e) {
            $this->logger?->error('Supply job {job} at {supplier}: {error}', ['job' => $job->getReference(), 'supplier' => $job->getSupplier(), 'error' => $e->getMessage()]);
            if ($failJob) {
                $previous = $job->getStatus();
                if ($job->update(SupplyStatus::FAILED, message: $e->getMessage())) {
                    $this->dispatcher?->dispatch(new SupplyJobChangedEvent($job, $previous));
                }
            }
        }
    }

    private function record(SupplyJob $job, SupplyResult $result): void
    {
        $previous = $job->getStatus();
        if ($job->update($result->status, $result->reference, $result->trackingNumber, $result->trackingUrl, $result->carrier, $result->message)) {
            $this->dispatcher?->dispatch(new SupplyJobChangedEvent($job, $previous));
        }
    }

    /** A line's own recipient, else the order's shipping address. */
    private function recipient(Order $order, ?array $own): ?SupplyRecipient
    {
        if ($own) {
            return SupplyRecipient::fromArray($own);
        }
        $address = $order->getShippingAddress();
        if (!$address) {
            return null;
        }

        return new SupplyRecipient(
            (string) ($address->getName() ?: $order->getCustomer()),
            array_values(array_filter([$address->getStreetAddress(), $address->getAffix()])),
            (string) $address->getZipCode(),
            (string) $address->getCity(),
            strtoupper((string) ($address->getCountry() ?: 'FR')),
            $order->getCustomer()?->getEmail(),
            $address->getPhone(),
        );
    }
}
