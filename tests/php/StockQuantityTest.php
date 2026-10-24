<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests;

use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\CatalogData\Tests\Stub\StockLevelStub;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The guarded {@code quantity()} read against {@code silvershop/stock}'s {@code getStockLevel()}, simulated with
 * {@link StockLevelStub} so it runs without the stock module installed: a finite level flows through, the unlimited
 * sentinel ({@code "-1"}) and an untracked record ({@code null}) are omitted.
 */
class StockQuantityTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected static $required_extensions = [
        Product::class => [StockLevelStub::class],
    ];

    private CatalogProductDataFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        StockLevelStub::$level = null;
        $this->factory = CatalogProductDataFactory::create();
    }

    protected function tearDown(): void
    {
        StockLevelStub::$level = null;
        parent::tearDown();
    }

    private function newProduct(): Product
    {
        $product = Product::create([
            'Title' => 'Rain Jacket',
            'BasePrice' => 89.95,
            'InternalItemID' => 'RJ-001',
            'AllowPurchase' => true,
        ]);
        $product->write();

        return $product;
    }

    public function testFiniteStockLevelFlowsIntoQuantity(): void
    {
        StockLevelStub::$level = '7';
        $this->assertSame(7, $this->factory->forProduct($this->newProduct())->quantity());
    }

    public function testUnlimitedSentinelIsOmitted(): void
    {
        StockLevelStub::$level = '-1';
        $this->assertNull($this->factory->forProduct($this->newProduct())->quantity());
    }

    public function testUntrackedRecordIsOmitted(): void
    {
        StockLevelStub::$level = null;
        $this->assertNull($this->factory->forProduct($this->newProduct())->quantity());
    }

    public function testZeroStockIsReported(): void
    {
        // A tracked record at zero is a real, feed-worthy fact (out of stock) — distinct from "untracked".
        StockLevelStub::$level = '0';
        $this->assertSame(0, $this->factory->forProduct($this->newProduct())->quantity());
    }
}
