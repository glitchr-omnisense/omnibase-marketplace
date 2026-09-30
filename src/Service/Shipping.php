<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Address\ShippingAddress;
use Base\Marketplace\Entity\Order\Method\ShippingMethod;
use Base\Marketplace\Entity\Order\Shipment;
use Base\Marketplace\Enum\ShippingRate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Posting physical goods: whether an order needs it, the methods that can
 * carry it and what each costs, the address it goes to, and the shipment
 * staff record when it leaves.
 *
 * Rates: a flat rate costs its unit price once per order; a priority rate
 * costs its unit price per shipping unit (weight-based when products have
 * one, else one per item); an international rate is flat. A method in a
 * currency other than the order's is not offered.
 */
final class Shipping
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function needsShipping(Order $order): bool
    {
        foreach ($order->getItems() as $item) {
            if ($item->getProduct()?->isShippable()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<array{method: ShippingMethod, charge: int}> */
    public function optionsFor(Order $order): array
    {
        $options = [];
        foreach ($this->entityManager->getRepository(ShippingMethod::class)->findAll() as $method) {
            if ($method->getCurrency() && strtoupper($method->getCurrency()) !== strtoupper((string) $order->getCurrency())) {
                continue;
            }
            $options[] = ['method' => $method, 'charge' => $this->chargeFor($method, $order)];
        }
        usort($options, fn ($a, $b) => $a['charge'] <=> $b['charge']);

        return $options;
    }

    public function chargeFor(ShippingMethod $method, Order $order): int
    {
        $unit = (int) $method->getUnitPrice();
        if (ShippingRate::PRIORITY_RATE !== $method->getTypeRate()) {
            return $unit;
        }
        $units = 0.0;
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if ($product?->isShippable()) {
                $units += max(1.0, (float) $product->getShippingUnits()) * (int) $item->getQuantity();
            }
        }

        return (int) round($unit * max(1.0, ceil($units)));
    }

    /** The address the order goes to, or the member's last one, as form values. */
    public function addressOf(Order $order): array
    {
        $address = $order->getShippingAddress();
        if (!$address && $order->getCustomer()) {
            $last = $this->entityManager->getRepository(Order::class)->createQueryBuilder('o')
                ->andWhere('o.customer = :customer')->andWhere('o.shippingAddress IS NOT NULL')->andWhere('o.id != :id')
                ->setParameter('customer', $order->getCustomer())->setParameter('id', (int) $order->getId())
                ->orderBy('o.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            $address = $last?->getShippingAddress();
        }

        return [
            'name' => $address?->getName() ?? '',
            'street' => $address?->getStreetAddress() ?? '',
            'additional' => $address?->getAffix() ?? '',
            'zip' => $address?->getZipCode() ?? '',
            'city' => $address?->getCity() ?? '',
            'country' => $address?->getCountry() ?? 'FR',
            'phone' => $address?->getPhone() ?? '',
        ];
    }

    /**
     * Put the address and the chosen method on the order.
     *
     * @return string|null an error key (shipping.error.*), or null when all is set
     */
    public function apply(Order $order, array $fields, int $methodId): ?string
    {
        $fields = array_map(fn ($v) => trim((string) $v), $fields);
        foreach (['name', 'street', 'zip', 'city', 'country'] as $required) {
            if ('' === ($fields[$required] ?? '')) {
                return 'shipping.error.address';
            }
        }
        if (!preg_match('/^[A-Za-z]{2}$/', $fields['country'])) {
            return 'shipping.error.country';
        }
        $option = null;
        foreach ($this->optionsFor($order) as $candidate) {
            if ($candidate['method']->getId() === $methodId) {
                $option = $candidate;
            }
        }
        if (!$option) {
            return 'shipping.error.method';
        }

        $address = $order->getShippingAddress() ?? new ShippingAddress($fields['name']);
        $address->setName($fields['name']);
        $address->setStreetAddress($fields['street']);
        $address->setAffix(($fields['additional'] ?? '') ?: null);
        $address->setZipCode($fields['zip']);
        $address->setCity($fields['city']);
        $address->setCountry(strtoupper($fields['country']));
        if (method_exists($address, 'setPhone')) {
            $address->setPhone(($fields['phone'] ?? '') ?: null);
        }
        $customer = $order->getCustomer();
        if ($customer && method_exists($address, 'setUser')) {
            $address->setUser($customer);
        }
        $address->setOrder($order);
        $order->setShippingAddress($address);
        $this->entityManager->persist($address);

        $order->setShippingMethod($option['method']);
        $order->setShippingCharge($option['charge']);

        return null;
    }

    /** Staff send the parcel: a shipment with its tracking number, the order marked shipped. */
    public function ship(Order $order, string $trackingNumber): Shipment
    {
        $shipment = new Shipment();
        $shipment->setOrder($order);
        $shipment->setMethod($order->getShippingMethod());
        $shipment->setNumber($trackingNumber);
        $order->addShipment($shipment);
        $order->markAsShipped();
        $this->entityManager->persist($shipment);

        return $shipment;
    }

    /** @return Order[] paid orders with an address that have not left yet */
    public function queue(): array
    {
        $orders = $this->entityManager->getRepository(Order::class)->findBy([], ['id' => 'DESC'], 200);

        return array_values(array_filter($orders, fn (Order $o) => $o->isConfirmed() && null !== $o->getShippingAddress()));
    }

    public static function trackingUrl(?ShippingMethod $method, ?string $number): ?string
    {
        if (!$method || !$number || !$method->getTrackingUrl()) {
            return null;
        }

        return str_contains($method->getTrackingUrl(), '{number}')
            ? str_replace('{number}', rawurlencode($number), $method->getTrackingUrl())
            : $method->getTrackingUrl().rawurlencode($number);
    }
}
