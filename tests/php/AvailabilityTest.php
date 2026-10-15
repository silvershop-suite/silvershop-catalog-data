<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests;

use SilverShop\CatalogData\Availability;
use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\CatalogData\Tests\Stub\StockAvailabilityStub;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Availability is driven by the shop's real stock state: when a {@code hasAvailableStock()} signal is present (as
 * silvershop/stock provides, simulated here by {@see StockAvailabilityStub}) the enum follows it; a non-purchasable
 * buyable is always out of stock, whatever the stock says.
 */
class AvailabilityTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected static array $required_extensions = [
        Product::class => [StockAvailabilityStub::class],
    ];

    private CatalogProductDataFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        StockAvailabilityStub::$available = true;
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

    public function testInStockWhenStockAvailable(): void
    {
        StockAvailabilityStub::$available = true;
        $this->assertSame(Availability::InStock, $this->factory->forProduct($this->newProduct())->availability());
    }

    public function testOutOfStockWhenStockUnavailable(): void
    {
        StockAvailabilityStub::$available = false;
        $this->assertSame(Availability::OutOfStock, $this->factory->forProduct($this->newProduct())->availability());
    }

    public function testOutOfStockWhenNotPurchasableRegardlessOfStock(): void
    {
        StockAvailabilityStub::$available = true;
        $product = $this->newProduct(['AllowPurchase' => false]);
        $this->assertSame(Availability::OutOfStock, $this->factory->forProduct($product)->availability());
    }
}
