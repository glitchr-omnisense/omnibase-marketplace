<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Address\ShippingAddress;
use Base\Marketplace\Entity\Order\Method\ShippingMethod;
use Base\Marketplace\Entity\Order\Shipment;
use Base\Marketplace\Enum\ShippingRate;
use Doctrine\ORM\EntityManagerInterface;
use Omnibus\GatewayInterface as Carrier;
use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment as Consignment;
use Omnibus\Model\Tracking;
use Omnibus\Registry as Carriers;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Posting physical goods: whether an order needs it, the methods that can
 * carry it and what each costs, the address it goes to, and the shipment
 * staff record when it leaves.
 *
 * Rates: a flat rate costs its unit price once per order; a priority rate
 * costs its unit price per shipping unit (weight-based when products have
 * one, else one per item); an international rate is flat. A method in a
 * currency other than the order's is not offered. What the buyer pays is
 * set by hand on the method - the shop's price - whatever the carrier
 * charges the shop.
 *
 * The carrier itself is glitchr/omnibus's: a ShippingMethod names a gateway
 * configured under omnibus.gateways (`gatewayName`), and when that package
 * is installed the shipment is booked there (book(): the label, the
 * tracking number on the Shipment) and followed (track()). The sender is
 * marketplace.shipping.sender.
 */
final class Shipping
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ParameterBagInterface $parameters = null,
        private readonly ?Carriers $carriers = null,
    ) {
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

    // --- The carrier (glitchr/omnibus) ----------------------------------------

    /** The omnibus carrier the method names (its gatewayName), when that package is installed and the gateway configured. */
    public function carrierFor(?ShippingMethod $method): ?Carrier
    {
        $name = $method?->getGatewayName();
        if (null === $this->carriers || null === $name || '' === $name || !$this->carriers->has($name)) {
            return null;
        }

        return $this->carriers->get($name);
    }

    /** The order as a carrier sees it: from the shop's sender to the buyer's address, one parcel. */
    public function consignmentFor(Order $order, ?ShippingMethod $method = null): Consignment
    {
        $method ??= $order->getShippingMethod();
        $address = $order->getShippingAddress() ?? throw new \LogicException('The order has no shipping address.');
        $customer = $order->getCustomer();
        $parameters = $method?->getGatewayParameters() ?? [];

        return new Consignment(
            $this->sender(),
            new Address((string) $address->getName(), array_values(array_filter([(string) $address->getStreetAddress(), (string) $address->getAffix()])), (string) $address->getZipCode(), (string) $address->getCity(), strtoupper((string) ($address->getCountry() ?: 'FR')), null, $customer?->getEmail(), $address->getPhone()),
            [$this->parcelFor($order)],
            $parameters['service'] ?? null,
            $parameters['pickup_point'] ?? null,
            (string) ($order->getReference() ?? '#'.$order->getId()),
            array_diff_key($parameters, ['service' => 1, 'pickup_point' => 1, 'rates' => 1]),
        );
    }

    /** What the carrier's own tariff says for the order, for information: the buyer pays chargeFor(). */
    public function ratesFor(Order $order, ShippingMethod $method): array
    {
        return $this->carrierFor($method)?->rate($this->consignmentFor($order, $method)) ?? [];
    }

    /** The shipment booked with its carrier: the label, the tracking number on the shipment. */
    public function book(Shipment $shipment): Label
    {
        $order = $shipment->getOrder() ?? throw new \LogicException('The shipment belongs to no order.');
        $method = $shipment->getMethod() ?? $order->getShippingMethod();
        $carrier = $this->carrierFor($method) ?? throw new \LogicException(sprintf('The shipping method "%s" names no configured carrier.', $method?->getSlug() ?? '?'));

        $label = $carrier->ship($this->consignmentFor($order, $method));
        $shipment->setNumber($label->trackingNumber);

        return $label;
    }

    /** Where the carrier says the shipment is; null when it names no carrier. */
    public function track(Shipment $shipment, string $locale = 'fr'): ?Tracking
    {
        $method = $shipment->getMethod() ?? $shipment->getOrder()?->getShippingMethod();
        $number = $shipment->getNumber();

        return null !== $number && '' !== $number ? $this->carrierFor($method)?->track($number, $locale) : null;
    }

    /** The parcel: the shippable items' weights (grams) and the order's value. */
    public function parcelFor(Order $order): Parcel
    {
        $grams = 0;
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (!$product?->isShippable()) {
                continue;
            }
            $weight = (float) ($product->getWeight() ?? 0);
            $grams += (int) round(match (strtolower((string) $product->getWeightUnit())) { 'kg' => $weight * 1000, 'lb', 'lbs' => $weight * 453.592, 'oz' => $weight * 28.3495, default => $weight }) * (int) $item->getQuantity();
        }

        return new Parcel(max(100, $grams), null, null, null, (int) $order->getNetPrice(), strtoupper((string) $order->getCurrency()), (string) ($order->getReference() ?? ''));
    }

    /** The shop's address, marketplace.shipping.sender. */
    public function sender(): Address
    {
        $sender = $this->parameters?->has('marketplace.shipping.sender') ? (array) $this->parameters->get('marketplace.shipping.sender') : [];
        if ('' === (string) ($sender['name'] ?? '')) {
            throw new \LogicException('No sender: set marketplace.shipping.sender (name, street, postcode, city, country).');
        }

        return new Address((string) $sender['name'], array_values(array_filter((array) ($sender['street'] ?? []))), (string) ($sender['postcode'] ?? ''), (string) ($sender['city'] ?? ''), strtoupper((string) ($sender['country'] ?? 'FR')), $sender['company'] ?? null, $sender['email'] ?? null, $sender['phone'] ?? null);
    }
}
