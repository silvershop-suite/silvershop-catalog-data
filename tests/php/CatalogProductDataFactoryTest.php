<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests;

use SilverShop\CatalogData\Availability;
use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\CatalogData\Condition;
use SilverShop\CatalogData\Tests\Stub\SellingPriceDiscountStub;
use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Model\Variation\AttributeType;
use SilverShop\Model\Variation\AttributeValue;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The factory's normalised fields: title, description, link, price/sale formatting, currency, stock-driven
 * availability, product_type, item_group_id, variant attributes and condition.
 */
class CatalogProductDataFactoryTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected static array $required_extensions = [
        Product::class => [SellingPriceDiscountStub::class],
    ];

    private CatalogProductDataFactory $factory;
    private ProductCategory $clothing;
    private ProductCategory $jackets;

    protected function setUp(): void
    {
        parent::setUp();

        SellingPriceDiscountStub::$factor = 1.0;

        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        Config::modify()->set(ShopConfigExtension::class, 'base_currency', 'EUR');

        $this->clothing = ProductCategory::create(['Title' => 'Clothing']);
        $this->clothing->write();

        $this->jackets = ProductCategory::create(['Title' => 'Jackets', 'ParentID' => $this->clothing->ID]);
        $this->jackets->write();

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

    public function testTitleForProduct(): void
    {
        $this->assertSame('Rain Jacket', $this->factory->forProduct($this->newProduct())->title());
    }

    public function testTitleForVariationCombinesParentAndVariant(): void
    {
        $product = $this->newProduct();
        $type = AttributeType::create(['Name' => 'Color', 'Label' => 'Colour']);
        $type->write();
        $value = AttributeValue::create(['Value' => 'Red', 'TypeID' => $type->ID]);
        $value->write();

        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 89.95, 'InternalItemID' => 'RJ-RED']);
        $variation->write();
        $variation->AttributeValues()->add($value);

        $title = $this->factory->forVariation($variation)->title();
        $this->assertStringContainsString('Rain Jacket', $title);
        $this->assertStringContainsString('Red', $title);
    }

    public function testDescriptionPrefersMetaDescription(): void
    {
        $product = $this->newProduct(['MetaDescription' => 'A waterproof rain jacket.', 'Content' => '<p>Ignored.</p>']);
        $this->assertSame('A waterproof rain jacket.', $this->factory->forProduct($product)->description());
    }

    public function testDescriptionFallsBackToStrippedContent(): void
    {
        $product = $this->newProduct(['Content' => "<p>Keeps you  <strong>dry</strong>.</p>\n<p>Lightweight.</p>"]);
        $this->assertSame('Keeps you dry. Lightweight.', $this->factory->forProduct($product)->description());
    }

    public function testDescriptionIsTruncated(): void
    {
        Config::modify()->set(CatalogProductDataFactory::class, 'description_max_length', 10);
        $product = $this->newProduct(['MetaDescription' => 'This description is far too long to keep.']);

        $description = $this->factory->forProduct($product)->description();
        $this->assertSame(10, mb_strlen($description));
        $this->assertStringEndsWith('…', $description);
    }

    public function testLinkIsAbsolute(): void
    {
        $link = $this->factory->forProduct($this->newProduct())->link();
        $this->assertStringStartsWith('http', $link);
        $this->assertStringContainsString('rain-jacket', strtolower($link));
    }

    public function testPriceWithoutReduction(): void
    {
        $data = $this->factory->forProduct($this->newProduct());

        $this->assertSame(89.95, $data->price());
        $this->assertNull($data->salePrice());
        $this->assertSame('89.95 EUR', $data->formattedPrice());
        $this->assertNull($data->formattedSalePrice());
    }

    public function testSalePriceWhenReduced(): void
    {
        SellingPriceDiscountStub::$factor = 0.5; // selling price becomes half the base price
        $data = $this->factory->forProduct($this->newProduct(['BasePrice' => 100.0]));

        $this->assertSame(100.0, $data->price());
        $this->assertSame(50.0, $data->salePrice());
        $this->assertSame('100.00 EUR', $data->formattedPrice());
        $this->assertSame('50.00 EUR', $data->formattedSalePrice());
    }

    public function testCurrency(): void
    {
        $this->assertSame('EUR', $this->factory->forProduct($this->newProduct())->currency());
    }

    public function testAvailabilityInStockWhenPurchasable(): void
    {
        $this->assertSame(
            Availability::InStock,
            $this->factory->forProduct($this->newProduct())->availability()
        );
    }

    public function testAvailabilityOutOfStockWhenNotPurchasable(): void
    {
        $product = $this->newProduct(['AllowPurchase' => false]);
        $this->assertSame(
            Availability::OutOfStock,
            $this->factory->forProduct($product)->availability()
        );
    }

    public function testProductTypeIsCategoryPath(): void
    {
        $this->assertSame('Clothing > Jackets', $this->factory->forProduct($this->newProduct())->productType());
    }

    public function testProductTypeNullWhenUncategorised(): void
    {
        $product = $this->newProduct(['ParentID' => 0]);
        $this->assertNull($this->factory->forProduct($product)->productType());
    }

    public function testAttributesFromVariation(): void
    {
        $product = $this->newProduct();
        $type = AttributeType::create(['Name' => 'Size', 'Label' => 'Size']);
        $type->write();
        $value = AttributeValue::create(['Value' => 'L', 'TypeID' => $type->ID]);
        $value->write();

        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 89.95]);
        $variation->write();
        $variation->AttributeValues()->add($value);

        $this->assertSame(['Size' => 'L'], $this->factory->forVariation($variation)->attributes());
    }

    public function testProductHasNoAttributes(): void
    {
        $this->assertSame([], $this->factory->forProduct($this->newProduct())->attributes());
    }

    public function testConditionDefaultsToNew(): void
    {
        $this->assertSame(Condition::New_, $this->factory->forProduct($this->newProduct())->condition());
    }

    public function testConditionOverride(): void
    {
        $product = $this->newProduct(['Condition' => 'used']);
        $this->assertSame(Condition::Used, $this->factory->forProduct($product)->condition());
    }

    public function testVariationInheritsProductCondition(): void
    {
        $product = $this->newProduct(['Condition' => 'refurbished']);
        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 89.95]);
        $variation->write();

        $this->assertSame(Condition::Refurbished, $this->factory->forVariation($variation)->condition());
    }

    public function testImageLinkNullWhenNoImage(): void
    {
        $data = $this->factory->forProduct($this->newProduct());
        $this->assertNull($data->imageLink());
        $this->assertSame([], $data->additionalImageLinks());
    }
}
