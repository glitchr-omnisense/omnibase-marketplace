<?php

namespace Tests\Base\Market\Shopify;

use Base\Market\Shopify\Catalogue\ProductData;
use Base\Market\Shopify\Catalogue\ProductMapper;
use PHPUnit\Framework\TestCase;

/**
 * The mapper is pure, so this runs against recorded payloads with no kernel,
 * no database and no Shopify.
 *
 * The test that earns its keep is theSameProductThroughBothShapes(): the
 * products(...) query returns gids, nested variants and camelCase, while the
 * products/update webhook POSTs numeric ids, a flat array and snake_case -
 * for the same product, with no warning anywhere that they differ. Asserting
 * they converge is what stops that turning into a bug months later.
 */
final class ProductMapperTest extends TestCase
{
    private ProductMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ProductMapper();
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$name), true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testTheGraphQLShape(): void
    {
        $products = $this->mapper->fromGraphQLNode($this->fixture('product.graphql.json'), 'EUR');

        self::assertCount(1, $products);
        $product = $products[0];

        self::assertSame('gid://shopify/Product/8472913847', $product->productGid);
        self::assertSame('gid://shopify/ProductVariant/44821390', $product->variantGid);
        self::assertSame('44821390', $product->legacyVariantId);
        self::assertSame('Enamel Mug', $product->title);
        self::assertSame(1250, $product->unitPrice);
        self::assertSame('MUG-001', $product->sku);
        self::assertSame('5012345678900', $product->barcode);
        self::assertSame(17, $product->stock);
        self::assertSame(['kitchen', 'gift'], $product->tags);
        self::assertTrue($product->isOnlyVariant);
    }

    public function testTheWebhookShape(): void
    {
        $products = $this->mapper->fromRestPayload($this->fixture('product.webhook.json'), 'EUR');

        self::assertCount(1, $products);
        self::assertSame('gid://shopify/Product/8472913847', $products[0]->productGid);
        self::assertSame(1250, $products[0]->unitPrice);
        self::assertSame(17, $products[0]->stock);
    }

    /**
     * The whole point. Both fixtures describe the same mug.
     */
    public function testTheSameProductThroughBothShapes(): void
    {
        $fromQuery = $this->mapper->fromGraphQLNode($this->fixture('product.graphql.json'), 'EUR')[0];
        $fromWebhook = $this->mapper->fromRestPayload($this->fixture('product.webhook.json'), 'EUR')[0];

        foreach (['productGid', 'variantGid', 'legacyVariantId', 'title', 'variantTitle', 'description',
                  'handle', 'tags', 'status', 'availableForSale', 'unitPrice', 'currency', 'sku',
                  'barcode', 'stock', 'inventoryItemId', 'imageUrl', 'isOnlyVariant'] as $field) {
            self::assertSame($fromQuery->$field, $fromWebhook->$field, sprintf('"%s" differs between the two shapes', $field));
        }
    }

    /** "Default Title" is Shopify's placeholder, not a name for a product page. */
    public function testTheDefaultVariantTitleIsDropped(): void
    {
        self::assertNull($this->mapper->fromGraphQLNode($this->fixture('product.graphql.json'), 'EUR')[0]->variantTitle);
    }

    /** Untracked inventory is unlimited, which this bundle spells "null". */
    public function testUntrackedInventoryBecomesNullStock(): void
    {
        $node = $this->fixture('product.graphql.json');
        $node['variants']['nodes'][0]['inventoryItem']['tracked'] = false;

        self::assertNull($this->mapper->fromGraphQLNode($node, 'EUR')[0]->stock);

        $payload = $this->fixture('product.webhook.json');
        $payload['variants'][0]['inventory_management'] = null;

        self::assertNull($this->mapper->fromRestPayload($payload, 'EUR')[0]->stock);
    }

    public function testSeveralVariantsAreFlaggedAsSuch(): void
    {
        $node = $this->fixture('product.graphql.json');
        $second = $node['variants']['nodes'][0];
        $second['id'] = 'gid://shopify/ProductVariant/44821391';
        $second['title'] = 'Large';
        $second['sku'] = 'MUG-002';
        $node['variants']['nodes'][] = $second;

        $products = $this->mapper->fromGraphQLNode($node, 'EUR');

        self::assertCount(2, $products);
        self::assertFalse($products[0]->isOnlyVariant);
        self::assertSame('Large', $products[1]->variantTitle);
    }

    public function testBlankIdentifiersBecomeNull(): void
    {
        $node = $this->fixture('product.graphql.json');
        $node['variants']['nodes'][0]['sku'] = '';
        $node['variants']['nodes'][0]['barcode'] = '   ';

        $product = $this->mapper->fromGraphQLNode($node, 'EUR')[0];

        self::assertNull($product->sku);
        self::assertNull($product->barcode);
    }

    /** A draft or archived product is not on sale, whatever its stock says. */
    public function testADraftProductIsNotAvailable(): void
    {
        $payload = $this->fixture('product.webhook.json');
        $payload['status'] = 'draft';

        $product = $this->mapper->fromRestPayload($payload, 'EUR')[0];

        self::assertSame('DRAFT', $product->status);
        self::assertFalse($product->availableForSale);
    }

    /**
     * The fingerprint covers only the fields the shop has delegated. A change
     * to an unowned field must not provoke a write, or the sync would churn
     * updatedAt - and every cache keyed on it - for nothing.
     */
    public function testTheFingerprintIgnoresUnownedFields(): void
    {
        $owned = ['price', 'stock'];
        $a = $this->mapper->fromGraphQLNode($this->fixture('product.graphql.json'), 'EUR')[0];

        $changed = $this->fixture('product.graphql.json');
        $changed['title'] = 'A completely different name';
        $changed['descriptionHtml'] = '<p>Rewritten.</p>';
        $b = $this->mapper->fromGraphQLNode($changed, 'EUR')[0];

        self::assertSame($a->fingerprint($owned), $b->fingerprint($owned));
        self::assertNotSame($a->fingerprint(['title']), $b->fingerprint(['title']));
    }

    public function testTheFingerprintMovesWhenAnOwnedFieldDoes(): void
    {
        $owned = ['price', 'stock'];
        $a = $this->mapper->fromGraphQLNode($this->fixture('product.graphql.json'), 'EUR')[0];

        $changed = $this->fixture('product.graphql.json');
        $changed['variants']['nodes'][0]['price'] = '13.00';
        $b = $this->mapper->fromGraphQLNode($changed, 'EUR')[0];

        self::assertNotSame($a->fingerprint($owned), $b->fingerprint($owned));
    }

    public function testAProductWithNoVariantsMapsToNothing(): void
    {
        self::assertSame([], $this->mapper->fromGraphQLNode(['id' => 'gid://shopify/Product/1', 'variants' => ['nodes' => []]], 'EUR'));
        self::assertSame([], $this->mapper->fromRestPayload(['id' => 1, 'variants' => []], 'EUR'));
    }

    public function testProductDataIsTheSameObjectEitherWay(): void
    {
        self::assertInstanceOf(ProductData::class, $this->mapper->fromGraphQLNode($this->fixture('product.graphql.json'), 'EUR')[0]);
        self::assertInstanceOf(ProductData::class, $this->mapper->fromRestPayload($this->fixture('product.webhook.json'), 'EUR')[0]);
    }
}
