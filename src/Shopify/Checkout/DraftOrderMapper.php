<?php

namespace Base\Marketplace\Shopify\Checkout;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Shopify\Api\Money;
use Base\Marketplace\Shopify\Repository\ProductLinkRepository;

/**
 * A marketplace Order -> a Shopify DraftOrderInput.
 *
 * Pure, bar one indexed lookup: arrays out, no HTTP, no flush.
 *
 * Draft orders rather than a Storefront cart, because a draft order's custom
 * line items take the price they are given. Pricing::reprice() has just run
 * over this cart, applying promotions, coupons, fees, shipping and VAT; a
 * Storefront cart would throw all of that away and recompute from Shopify's
 * own catalogue, and Transaction::$totalAmount - already set from
 * Order::getNetPrice() - would stop matching what the buyer is actually
 * charged. On any order that is not perfectly plain, the two would drift.
 *
 * The happy side effect is that a custom line item needs no variant id, so
 * taking payment through Shopify does not require the catalogue to have been
 * synced first. When a link does exist the variant is named anyway: Shopify
 * then decrements its own stock and the order reads properly in its admin.
 */
class DraftOrderMapper
{
    public const CREATE = <<<'GRAPHQL'
        mutation DraftOrderCreate($input: DraftOrderInput!) {
          draftOrderCreate(input: $input) {
            draftOrder { id name invoiceUrl status totalPrice }
            userErrors { field message }
          }
        }
        GRAPHQL;

    public const FETCH = <<<'GRAPHQL'
        query DraftOrder($id: ID!) {
          draftOrder(id: $id) {
            id
            status
            order { id name displayFinancialStatus }
          }
        }
        GRAPHQL;

    /** The custom attribute both sides correlate on. */
    public const REFERENCE_KEY = 'marketplace_reference';

    public function __construct(
        private readonly ?ProductLinkRepository $links = null,
    ) {
    }

    /**
     * @param string[] $tags
     *
     * @return array<string, mixed> a DraftOrderInput
     */
    public function map(Order $order, string $shop = '', array $tags = []): array
    {
        $currency = strtoupper((string) $order->getCurrency());
        $lineItems = [];

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $quantity = max(1, (int) $item->getQuantity());
            $unitPrice = (int) $item->getUnitPrice();

            $line = [
                'quantity' => $quantity,
                'originalUnitPriceWithCurrency' => [
                    'amount' => Money::toDecimal($unitPrice, $currency),
                    'currencyCode' => $currency,
                ],
            ];

            $variantGid = $this->variantGid($shop, $product);
            if (null !== $variantGid) {
                $line['variantId'] = $variantGid;
            } else {
                // A custom line item: Shopify has never heard of this product
                // and does not need to.
                $line['title'] = (string) ($product ?? $item);
                $line['requiresShipping'] = $product?->isShippable() ?? false;
                if ($product?->getEAN()) {
                    $line['sku'] = $product->getEAN();
                }
            }

            $lineItems[] = $line;
        }

        $input = [
            'lineItems' => $lineItems,
            'note' => sprintf('Order %s', (string) $order->getReference()),
            'customAttributes' => [
                ['key' => self::REFERENCE_KEY, 'value' => (string) $order->getReference()],
            ],
            'presentmentCurrencyCode' => $currency,
        ];

        if ($tags) {
            $input['tags'] = $tags;
        }

        $email = $order->getCustomer()?->getEmail();
        if ($email) {
            $input['email'] = $email;
        }

        // The charge this shop computed, carried across whole rather than
        // recomputed: the whole reason for choosing draft orders.
        $shipping = (int) $order->getShippingCharge();
        if ($shipping > 0) {
            $input['shippingLine'] = [
                'title' => (string) ($order->getShippingMethod() ?? 'Shipping'),
                'priceWithCurrency' => ['amount' => Money::toDecimal($shipping, $currency), 'currencyCode' => $currency],
            ];
        }

        $discount = (int) $order->getDiscountCharge();
        if ($discount > 0) {
            $input['appliedDiscount'] = [
                'title' => 'Discount',
                'value' => (float) Money::toDecimal($discount, $currency),
                'valueType' => 'FIXED_AMOUNT',
            ];
        }

        return $input;
    }

    private function variantGid(string $shop, ?object $product): ?string
    {
        if (null === $this->links || null === $product || '' === $shop || null === $product->getId()) {
            return null;
        }

        $link = $this->links->findOneBy(['shop' => $shop, 'product' => $product]);

        return $link?->getVariantGid() ?: null;
    }
}
