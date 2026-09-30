<?php

namespace Base\Marketplace\Shopify\Export;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Shopify\Api\Money;
use Base\Marketplace\Shopify\Repository\ProductLinkRepository;

/**
 * A paid marketplace Order -> a Shopify OrderCreateOrderInput.
 *
 * For orders paid somewhere else - by card here, by an in-game currency, by
 * bank transfer - that still need Shopify to pick, pack and label them. The
 * order arrives already paid: financialStatus PAID and a SALE transaction, so
 * nobody in the Shopify admin is waiting to capture anything.
 *
 * orderCreate is a recent Admin API mutation (the old road is
 * POST /admin/api/{v}/orders.json). Both the document and the input shape
 * live in this one class so that an api_version bump is one file to read.
 *
 * Note that writing an email and addresses to Shopify requires the app to
 * have been granted protected customer data access. That is a review with a
 * lead time, not a checkbox: without it this mutation is refused and the
 * message says so.
 */
class OrderMapper
{
    public const CREATE = <<<'GRAPHQL'
        mutation OrderCreate($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) {
          orderCreate(order: $order, options: $options) {
            order { id name }
            userErrors { field message }
          }
        }
        GRAPHQL;

    public const REFERENCE_KEY = 'marketplace_reference';

    public function __construct(
        private readonly ?ProductLinkRepository $links = null,
    ) {
    }

    /** @return array{order: array<string,mixed>, options: array<string,mixed>} */
    public function map(Order $order, string $shop = '', ?string $locationGid = null): array
    {
        $currency = strtoupper((string) $order->getCurrency());
        $reference = (string) $order->getReference();

        $lineItems = [];
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $quantity = max(1, (int) $item->getQuantity());

            $line = [
                'quantity' => $quantity,
                'priceSet' => $this->moneyBag((int) $item->getUnitPrice(), $currency),
                'requiresShipping' => $product?->isShippable() ?? false,
            ];

            $variantGid = $this->variantGid($shop, $product);
            if (null !== $variantGid) {
                $line['variantId'] = $variantGid;
            } else {
                $line['title'] = (string) ($product ?? $item);
            }

            if ($product?->getEAN()) {
                $line['sku'] = $product->getEAN();
            }

            $lineItems[] = $line;
        }

        $input = [
            // sourceIdentifier is indexed by Shopify and unique on our side,
            // so it is both the correlation key and the duplicate guard.
            'sourceIdentifier' => $reference,
            'sourceName' => 'base-bundle-marketplace',
            'currency' => $currency,
            'financialStatus' => 'PAID',
            'lineItems' => $lineItems,
            'customAttributes' => [
                ['key' => self::REFERENCE_KEY, 'value' => $reference],
            ],
            'transactions' => [[
                'kind' => 'SALE',
                'status' => 'SUCCESS',
                'amountSet' => $this->moneyBag((int) $order->getTotalPaid(), $currency),
                'gateway' => (string) ($order->getPaymentMethod()?->getGatewayFactory() ?? 'manual'),
            ]],
        ];

        $paidAt = $order->getPaidAt();
        if ($paidAt) {
            $input['processedAt'] = $paidAt->format(\DATE_ATOM);
        }

        $email = $order->getCustomer()?->getEmail();
        if ($email) {
            $input['email'] = $email;
        }

        $shipping = (int) $order->getShippingCharge();
        if ($shipping > 0) {
            $input['shippingLines'] = [[
                'title' => (string) ($order->getShippingMethod() ?? 'Shipping'),
                'priceSet' => $this->moneyBag($shipping, $currency),
            ]];
        }

        // The tax is already inside the prices this shop quotes, so say so
        // rather than letting Shopify add its own on top.
        $vat = (int) $order->getVatCharge();
        if ($vat > 0) {
            $input['taxesIncluded'] = true;
            $input['taxLines'] = [[
                'title' => 'VAT',
                'priceSet' => $this->moneyBag($vat, $currency),
                'rate' => 0,
            ]];
        }

        $shippingAddress = $this->address($order->getShippingAddress());
        if ($shippingAddress) {
            $input['shippingAddress'] = $shippingAddress;
        }

        $billingAddress = $this->address($order->getBillingAddress());
        if ($billingAddress) {
            $input['billingAddress'] = $billingAddress;
        }

        $options = ['sendReceipt' => false, 'sendFulfillmentReceipt' => false];
        if ($locationGid) {
            // Stock was already taken here when the order was confirmed, so
            // tell Shopify to mirror that rather than decide for itself.
            $options['inventoryBehaviour'] = 'DECREMENT_IGNORING_POLICY';
            $input['locationId'] = $locationGid;
        }

        return ['order' => $input, 'options' => $options];
    }

    private function moneyBag(int $minor, string $currency): array
    {
        return ['shopMoney' => ['amount' => Money::toDecimal($minor, $currency), 'currencyCode' => $currency]];
    }

    private function address(?object $address): ?array
    {
        if (null === $address) {
            return null;
        }

        $name = trim((string) $address->getName());
        $parts = explode(' ', $name, 2);

        return array_filter([
            'firstName' => $parts[0] ?? null,
            'lastName' => $parts[1] ?? null,
            'address1' => $address->getStreetAddress(),
            'address2' => $address->getAffix(),
            'city' => $address->getCity(),
            'zip' => $address->getZipCode(),
            'provinceCode' => $address->getState(),
            'countryCode' => $address->getCountry(),
            'phone' => $address->getPhone(),
        ], static fn ($v) => null !== $v && '' !== $v);
    }

    private function variantGid(string $shop, ?object $product): ?string
    {
        if (null === $this->links || null === $product || '' === $shop || null === $product->getId()) {
            return null;
        }

        return $this->links->findOneBy(['shop' => $shop, 'product' => $product])?->getVariantGid() ?: null;
    }
}
