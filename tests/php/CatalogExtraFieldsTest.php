<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests;

use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The additive marketplace-oriented VO fields that carry no soft-dependency: shipping weight/dimensions (core
 * Product/Variation fields), secondary categories (the explicit ProductCategories placements beyond the primary
 * productType path), the last_modified timestamp, and the quantity() "stock module absent" path (null).
 */
class CatalogExtraFieldsTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private CatalogProductDataFactory $factory;
    private ProductCategory $clothing;
    private ProductCategory $jackets;
    private ProductCategory $sale;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);

        $this->clothing = ProductCategory::create(['Title' => 'Clothing']);
        $this->clothing->write();
        $this->jackets = ProductCategory::create(['Title' => 'Jackets', 'ParentID' => $this->clothing->ID]);
        $this->jackets->write();
        $this->sale = ProductCategory::create(['Title' => 'Sale', 'ParentID' => $this->clothing->ID]);
        $this->sale->write();

        $this->factory = CatalogProductDataFactory::create();
    }

    private function newProduct(array $data = []): Product
    {
        $product = Product::create(array_merge([
            'Title' => 'Rain Jacket',
            'BasePrice' => 89.95,
            'InternalItemID' => 'RJ-001',
            'AllowPurchase' => true,
            'ParentID' => $this->jackets->ID,
        ], $data));
        $product->write();

        return $product;
    }

    public function testWeightAndDimensionsFromProduct(): void
    {
        $data = $this->factory->forProduct($this->newProduct([
            'Weight' => 1.25,
            'Height' => 10.0,
            'Width' => 20.0,
            'Depth' => 5.0,
        ]));

        $this->assertSame(1.25, $data->weight());
        $this->assertSame(10.0, $data->height());
        $this->assertSame(20.0, $data->width());
        $this->assertSame(5.0, $data->depth());
    }

    public function testWeightAndDimensionsNullWhenUnset(): void
    {
        $data = $this->factory->forProduct($this->newProduct());

        $this->assertNull($data->weight());
        $this->assertNull($data->height());
        $this->assertNull($data->width());
        $this->assertNull($data->depth());
    }

    public function testVariationInheritsProductDimensionsWhenUnset(): void
    {
        $product = $this->newProduct(['Weight' => 2.0, 'Height' => 30.0]);
        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 89.95, 'Weight' => 2.5]);
        $variation->write();

        $data = $this->factory->forVariation($variation);
        // Own weight wins; unset height falls back to the product's.
        $this->assertSame(2.5, $data->weight());
        $this->assertSame(30.0, $data->height());
        $this->assertNull($data->width());
    }

    public function testSecondaryCategoriesAreTheAdditionalPlacements(): void
    {
        $product = $this->newProduct();
        $product->ProductCategories()->add($this->sale);

        $data = $this->factory->forProduct($product);
        // Primary path is "Clothing > Jackets" (the tree parent); the extra placement surfaces as secondary.
        $this->assertSame('Clothing > Jackets', $data->productType());
        $this->assertSame(['Clothing > Sale'], $data->secondaryCategories());
    }

    public function testSecondaryCategoriesExcludeThePrimaryPath(): void
    {
        $product = $this->newProduct();
        // Adding the product's own primary category as an explicit placement must not duplicate it.
        $product->ProductCategories()->add($this->jackets);

        $this->assertSame([], $this->factory->forProduct($product)->secondaryCategories());
    }

    public function testSecondaryCategoriesEmptyByDefault(): void
    {
        $this->assertSame([], $this->factory->forProduct($this->newProduct())->secondaryCategories());
    }

    public function testLastModifiedIsIso8601Timestamp(): void
    {
        $data = $this->factory->forProduct($this->newProduct());

        $lastModified = $data->lastModified();
        $this->assertNotNull($lastModified);
        $this->assertNotFalse(strtotime($lastModified), 'last_modified must be a parseable timestamp');
        // ISO-8601 shape, e.g. 2026-10-24T13:32:00+00:00
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $lastModified);
    }

    public function testQuantityNullWhenStockModuleAbsent(): void
    {
        // No silvershop/stock (and no stub) → no getStockLevel() → quantity omitted.
        $this->assertNull($this->factory->forProduct($this->newProduct())->quantity());
    }
}
