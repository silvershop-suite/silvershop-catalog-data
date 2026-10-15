<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests;

use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The guarded soft-dependency reads — GTIN/MPN (silvershop/product-identity), brand (silvershop/brands) — exercised
 * BOTH ways: present (value flows into the VO) and absent (gracefully omitted as null). The "present" cases skip
 * when the optional module is not installed, so the suite stays green on a bare install and fully covered in the
 * clean-room where the path-repo modules are present.
 */
class GuardedSoftDepsTest extends SapphireTest
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
        $product = Product::create(array_merge([
            'Title' => 'Rain Jacket',
            'BasePrice' => 10.0,
            'InternalItemID' => 'RJ-001',
            'AllowPurchase' => true,
        ], $data));
        $product->write();

        return $product;
    }

    public function testGtinAndMpnOmittedWhenNoValue(): void
    {
        $data = $this->factory->forProduct($this->newProduct());
        $this->assertNull($data->gtin());
        $this->assertNull($data->mpn());
    }

    public function testGtinFlowsFromProductIdentityWhenSet(): void
    {
        if (!Product::singleton()->hasMethod('getResolvedGtin')) {
            $this->markTestSkipped('silvershop/product-identity is not installed.');
        }

        $product = $this->newProduct();
        $product->GTIN = '4006381333931'; // valid EAN-13
        $product->MPN = 'ABC-123';
        $product->write();

        $data = $this->factory->forProduct($product);
        $this->assertSame('4006381333931', $data->gtin());
        $this->assertSame('ABC-123', $data->mpn());
    }

    public function testVariationGtinFallsBackToProductThroughResolvedAccessor(): void
    {
        if (!Variation::singleton()->hasMethod('getResolvedGtin')) {
            $this->markTestSkipped('silvershop/product-identity is not installed.');
        }

        $product = $this->newProduct();
        $product->GTIN = '4006381333931';
        $product->write();

        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 10.0, 'InternalItemID' => 'RJ-RED']);
        $variation->write();

        // The variation has no GTIN of its own; product-identity's resolved accessor inherits the product's.
        $this->assertSame('4006381333931', $this->factory->forVariation($variation)->gtin());
    }

    public function testBrandOmittedWhenNoBrand(): void
    {
        $this->assertNull($this->factory->forProduct($this->newProduct())->brand());
    }

    public function testBrandFlowsFromBrandsModuleWhenSet(): void
    {
        $brandClass = 'SilverShop\\Brands\\Brand';
        if (!class_exists($brandClass) || !Product::singleton()->hasMethod('Brand')) {
            $this->markTestSkipped('silvershop/brands is not installed.');
        }

        $brand = $brandClass::create(['Title' => 'Acme']);
        $brand->write();

        $product = $this->newProduct();
        $product->BrandID = $brand->ID;
        $product->write();

        $this->assertSame('Acme', $this->factory->forProduct($product)->brand());
    }

    public function testIdentifierExistsFalseWhenNoIdentifiers(): void
    {
        // No GTIN, MPN or brand (whether the modules are installed or not) → explicit identifier_exists: no.
        $this->assertFalse($this->factory->forProduct($this->newProduct())->identifierExists());
    }

    public function testIdentifierExistsNullWhenGtinPresent(): void
    {
        if (!Product::singleton()->hasMethod('getResolvedGtin')) {
            $this->markTestSkipped('silvershop/product-identity is not installed.');
        }

        $product = $this->newProduct();
        $product->GTIN = '4006381333931';
        $product->write();

        // An identifier is present → omit identifier_exists (channel default "yes" applies).
        $this->assertNull($this->factory->forProduct($product)->identifierExists());
    }

    public function testIdentifierExistsNullWhenBrandPresent(): void
    {
        $brandClass = 'SilverShop\\Brands\\Brand';
        if (!class_exists($brandClass) || !Product::singleton()->hasMethod('Brand')) {
            $this->markTestSkipped('silvershop/brands is not installed.');
        }

        $brand = $brandClass::create(['Title' => 'Acme']);
        $brand->write();

        $product = $this->newProduct();
        $product->BrandID = $brand->ID;
        $product->write();

        $this->assertNull($this->factory->forProduct($product)->identifierExists());
    }
}
