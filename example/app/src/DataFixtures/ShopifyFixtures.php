<?php

namespace App\DataFixtures;

use App\Entity\User;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Product;
use Base\Market\Entity\Store;
use Base\Market\Enum\ProductAvailability;
use Base\Market\Shopify\Entity\ProductLink;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything a Shopify demo needs to exist before the first API call.
 *
 * Idempotent throughout: run it as many times as you like. It creates
 *
 *   - a store, the one market.shopify.catalogue.store names, because a
 *     synced product with no store cannot be put in a cart (Cart::add()
 *     refuses it);
 *   - a "shopify" payment method whose gatewayFactory matches
 *     ShopifyGateway::name(), which is the only thing that makes the gateway
 *     appear at checkout;
 *   - a member to shop as;
 *   - one local product that is deliberately NOT linked to Shopify, so that a
 *     sync can be seen to leave other people's products alone;
 *   - one product tagged shopify-unmanaged, to demonstrate the pin.
 *
 * Fixtures for the demo. A real application seeds its stores from the admin.
 */
class ShopifyFixtures
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return string[] what it did, for the command to print */
    public function load(string $storeSlug = 'boutique'): array
    {
        $done = [];

        $store = $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => $storeSlug]);
        if (!$store) {
            $store = new Store();
            $store->setTitle('La Boutique');
            $store->setSlug($storeSlug);
            $store->setExcerpt('The store synced products attach to.');
            $store->setCurrency('EUR');
            $store->setOpen(true);
            $this->entityManager->persist($store);
            $done[] = sprintf('store "%s" created', $storeSlug);
        } else {
            $done[] = sprintf('store "%s" already there', $storeSlug);
        }

        $method = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['slug' => 'shopify']);
        if (!$method) {
            $method = new PaymentMethod();
            $method->setLabel('Pay on Shopify');
            $method->setSlug('shopify');
            // This is the whole wiring: the slug finds the settings under
            // market.gateways.shopify, the factory finds the gateway service.
            $method->setGatewayFactory('shopify');
            $this->entityManager->persist($method);
            $done[] = 'payment method "shopify" created';
        } else {
            $done[] = 'payment method "shopify" already there';
        }

        $member = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'shopper']);
        if (!$member) {
            $member = new User();
            $member->setUsername('shopper');
            $member->setEmail('shopper@example.com');
            $member->setPlainPassword('shopper');
            $member->setRoles(['ROLE_USER']);
            $member->verify();
            $this->entityManager->persist($member);
            $done[] = 'member shopper created (password: shopper)';
        } else {
            $done[] = 'member shopper already there';
        }

        // A product the shop sells itself. No ProductLink points at it, so a
        // catalogue sync must never touch it - that is the point of it being
        // here.
        $done[] = $this->product($store, $member, 'demo-local-candle', 'Beeswax Candle (local)', 890, []);

        // A product a sync WOULD own, pinned by hand. ProductSynchronizer
        // skips anything carrying this tag.
        $done[] = $this->product($store, $member, 'demo-pinned-mug', 'Pinned Mug (shopify-unmanaged)', 1500, ['shopify-unmanaged']);

        $this->entityManager->flush();

        $links = $this->entityManager->getRepository(ProductLink::class)->count([]);
        $done[] = sprintf('%d Shopify link(s) recorded so far', $links);

        return $done;
    }

    private function product(Store $store, ?User $owner, string $slug, string $title, int $price, array $tags): string
    {
        $existing = $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]);
        if ($existing) {
            return sprintf('product "%s" already there', $slug);
        }

        $product = new Product($owner, $store, $price, 'EUR');
        $product->setTitle($title);
        $product->setSlug($slug);
        $product->setExcerpt($title.' - a demo fixture.');
        $product->setStock(5);
        $product->setAvailability(ProductAvailability::INSTOCK);
        $product->setStore($store);

        foreach ($tags as $label) {
            $feature = new \Base\Market\Entity\Product\Feature();
            $feature->setLabel($label);
            $feature->setSlug($label);
            $this->entityManager->persist($feature);
            $product->addFeature($feature);
        }

        $this->entityManager->persist($product);

        return sprintf('product "%s" created', $slug);
    }
}
