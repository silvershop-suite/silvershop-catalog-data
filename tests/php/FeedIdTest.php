<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests;

use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The locked {@see CatalogProductDataFactory::feedId()} contract: the SKU-first scheme with ID fallback, the
 * variation→parent composition, item_group_id = parent feedId, the ≤50-char cap, determinism, and the id_source
 * switch.
 */
class FeedIdTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private CatalogProductDataFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        $this->factory = CatalogProductDataFactory::create();
    }

    private function newProduct(array $data = []): Product
    {
        $product = Product::create(array_merge(['Title' => 'Rain Jacket', 'BasePrice' => 10.0], $data));
        $product->write();

        return $product;
    }

    private function newVariation(Product $product, array $data = []): Variation
    {
        $variation = Variation::create(array_merge(['ProductID' => $product->ID, 'Price' => 10.0], $data));
        $variation->write();

        return $variation;
    }

    public function testProductUsesInternalItemId(): void
    {
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $this->assertSame('RJ-001', $this->factory->feedId($product));
    }

    public function testProductFallsBackToIdWhenNoSku(): void
    {
        $product = $this->newProduct();
        $this->assertSame('product-' . $product->ID, $this->factory->feedId($product));
    }

    public function testVariationUsesOwnInternalItemId(): void
    {
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $variation = $this->newVariation($product, ['InternalItemID' => 'RJ-001-RED']);
        $this->assertSame('RJ-001-RED', $this->factory->feedId($variation));
    }

    public function testVariationFallsBackToParentFeedIdPlusVariationId(): void
    {
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $variation = $this->newVariation($product);
        $this->assertSame('RJ-001-' . $variation->ID, $this->factory->feedId($variation));
    }

    public function testVariationFallbackUsesProductIdWhenParentHasNoSku(): void
    {
        $product = $this->newProduct();
        $variation = $this->newVariation($product);
        $this->assertSame('product-' . $product->ID . '-' . $variation->ID, $this->factory->feedId($variation));
    }

    public function testItemGroupIdIsParentFeedId(): void
    {
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $variation = $this->newVariation($product, ['InternalItemID' => 'RJ-001-RED']);

        $data = $this->factory->forVariation($variation);
        $this->assertSame('RJ-001', $data->itemGroupId());
        $this->assertSame($this->factory->feedId($product), $data->itemGroupId());
    }

    public function testSimpleProductHasNoItemGroupId(): void
    {
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $this->assertNull($this->factory->forProduct($product)->itemGroupId());
    }

    public function testFeedIdIsCappedToConfiguredMax(): void
    {
        // InternalItemID is Varchar(30), so the cap is exercised with a lowered id_max_length; the default is 50.
        Config::modify()->set(CatalogProductDataFactory::class, 'id_max_length', 5);
        $product = $this->newProduct(['InternalItemID' => 'RJ-001234']);

        $id = $this->factory->feedId($product);
        $this->assertSame(5, strlen($id));
        $this->assertSame('RJ-00', $id);
    }

    public function testDefaultIdMaxLengthIsFifty(): void
    {
        $this->assertSame(50, (int) CatalogProductDataFactory::config()->get('id_max_length'));
    }

    public function testFeedIdIsStable(): void
    {
        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $this->assertSame($this->factory->feedId($product), $this->factory->feedId($product));

        $variation = $this->newVariation($product);
        $this->assertSame($this->factory->feedId($variation), $this->factory->feedId($variation));
    }

    public function testIdSourceIdIgnoresSku(): void
    {
        Config::modify()->set(CatalogProductDataFactory::class, 'id_source', CatalogProductDataFactory::ID_SOURCE_ID);

        $product = $this->newProduct(['InternalItemID' => 'RJ-001']);
        $variation = $this->newVariation($product, ['InternalItemID' => 'RJ-001-RED']);

        $this->assertSame('product-' . $product->ID, $this->factory->feedId($product));
        $this->assertSame('product-' . $product->ID . '-' . $variation->ID, $this->factory->feedId($variation));
    }
}
