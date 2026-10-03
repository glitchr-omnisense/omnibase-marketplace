<?php

namespace Base\Marketplace\Service;

use Base\Entity\User;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Address\ShippingAddress;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Entity\Quote\AbstractQuote;
use Base\Marketplace\Entity\Quote\QuoteLine;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Enum\OrderState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The client accepts a quote: it becomes an order of its own, at the
 * quote's prices - each line a product of the catalogue (or a one-off
 * product for a free line), its lots counted in units, the quote's
 * discount in each price - delivered where the quote says (an export's
 * order carries no VAT: Pricing\ExportExemption), waiting in the client's
 * carts to be paid like any other (a transfer, a card).
 *
 * omnibase/forge extends it: a studio's quote becomes one HourPack.
 */
class QuoteToOrder
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly ?Pricing $pricing = null,
        protected readonly ?RegionResolver $regions = null,
        #[Autowire('%marketplace.quotes.store%')] protected readonly ?string $storeSlug = null,
    ) {
    }

    /** @throws \DomainException quote.error.not_acceptable */
    public function accept(AbstractQuote $quote, User $client): Order
    {
        if (!$quote instanceof Quote) {
            throw new \LogicException(sprintf('%s has no order of its own: its bundle turns it into one.', $quote::class));
        }
        // Sent and still valid - or accepted already and not paid yet: back
        // to its order it goes.
        if (!$quote->isAcceptable() && !QuoteStatusGuard::isAwaitingPayment($quote)) {
            throw new \DomainException('quote.error.not_acceptable');
        }

        // The quote's row locked and read again: a double click (or two
        // tabs) made two orders, the client paid twice.
        $this->entityManager->beginTransaction();
        try {
            if (null !== $quote->getId()) {
                $this->entityManager->lock($quote, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($quote);
            }
            $order = $quote->getOrder();
            if (!$order || (!$order->isPaid() && OrderState::CART !== $order->getState() && !$order->isPending())) {
                $order = $this->orderOf($quote, $client);
                $quote->setOrder($order);
                $quote->setClient($quote->getClient() ?? $client);
                $quote->accept();
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }

        return $order;
    }

    /** The store the order is made in: the quote's, its first product's, else marketplace.quotes.store. */
    public function storeOf(Quote $quote): Store
    {
        if ($quote->getStore()) {
            return $quote->getStore();
        }
        foreach ($quote->getLines() as $line) {
            if ($store = $line->getProduct()?->getStore()) {
                return $store;
            }
        }
        $store = $this->storeSlug ? $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => $this->storeSlug]) : null;

        return $store ?? throw new \LogicException('A quote needs a store for its order: set it on the quote, or marketplace.quotes.store.');
    }

    /** The order's lines: [product, quantity, unit price after the discount, comment] - pure, for the tests and the page. */
    public function itemsOf(Quote $quote): array
    {
        $items = [];
        $keep = 100 - $quote->getDiscountPercent();
        foreach ($quote->getLines() as $line) {
            // By the unit when a lot's price shares out to the cent, else by the lot.
            $byUnit = 0 === $line->getLotPrice() % $line->getLotSize();
            $quantity = $byUnit ? $line->getUnits() : $line->getQuantity();
            $unit = $byUnit ? intdiv($line->getLotPrice(), $line->getLotSize()) : $line->getLotPrice();
            $comment = $line->getLotSize() > 1 ? sprintf('%d × %d', $line->getQuantity(), $line->getLotSize()) : null;
            $items[] = [$line, $quantity, (int) round($unit * $keep / 100), $comment];
        }

        return $items;
    }

    protected function orderOf(Quote $quote, User $client): Order
    {
        $store = $this->storeOf($quote);
        $order = new Order($store);
        $order->setCustomer($client);
        if ($this->regions) {
            $order->setRegion($this->regions->for($store));
        }
        $order->setCurrency($quote->getCurrency());
        $order->setQuoteReference($quote->getReference());
        $this->entityManager->persist($order);

        foreach ($this->itemsOf($quote) as [$line, $quantity, $unitPrice, $comment]) {
            $product = $line->getProduct() ?? $this->oneOff($line, $quote);
            $item = new OrderItem($product, $quantity);
            $item->setCurrency($quote->getCurrency());
            $item->setUnitPrice($unitPrice, $quote->getCurrency());
            $item->setComment(trim($line->getLabel().($comment ? ' ('.$comment.')' : '')));
            $order->addItem($item);
            $this->entityManager->persist($item);
        }

        $address = new ShippingAddress($quote->getCompanyName() ?? $quote->getContactName());
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $quote->getDeliveryAddress()))));
        $address->setStreetAddress($lines[0] ?? ($quote->getPlace() ?? '-'));
        $address->setAffix($lines[1] ?? null);
        $address->setZipCode('');
        $address->setCity($quote->getPlace() ?? ($lines[2] ?? ''));
        $address->setCountry($quote->getCountry() ?? 'FR');
        $address->setOrder($order);
        $order->setShippingAddress($address);
        $this->entityManager->persist($address);

        $this->entityManager->flush();
        $this->pricing?->reprice($order);

        return $order;
    }

    /** A free line (the transport, a label): a product of its own, in no store, never listed. */
    protected function oneOff(QuoteLine $line, Quote $quote): Product
    {
        $product = new Product(null, null, $line->getLotPrice(), $quote->getCurrency());
        $product->setTitle($line->getLabel() ?: $quote->getReference());
        $product->setSlug(strtolower($quote->getReference()).'-'.substr(hash('crc32b', $quote->getToken().$line->getPosition()), 0, 6));
        $this->entityManager->persist($product);

        return $product;
    }
}
