<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests;

use SilverShop\CatalogData\Extension\ProductConditionExtension;
use SilverShop\Page\Product;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\FieldType\DBEnum;

/**
 * The Condition field is applied to Product and renders on the edit form exactly once. Product auto-scaffolds
 * extension {@code $db} fields, so the extension must dedupe (remove-then-insert) to avoid the "appears twice"
 * FieldList fatal — {@link \SilverStripe\Forms\FieldList::getDataFields()} throws on a duplicate name, so a
 * successful call that still contains Condition proves the dedupe works.
 */
class ConditionExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    public function testExtensionIsApplied(): void
    {
        $this->assertTrue(Product::singleton()->hasExtension(ProductConditionExtension::class));
    }

    public function testConditionFieldRendersExactlyOnceAsDropdown(): void
    {
        $product = Product::create(['Title' => 'Rain Jacket', 'BasePrice' => 10.0]);

        // getDataFields() throws on a duplicate field name; reaching the assertions means no "appears twice" fatal.
        $dataFields = $product->getCMSFields()->getDataFields();

        $this->assertArrayHasKey('Condition', $dataFields);
        $this->assertInstanceOf(DropdownField::class, $dataFields['Condition']);

        $source = $dataFields['Condition']->getSource();
        $this->assertSame(['new', 'refurbished', 'used'], array_keys($source));
    }

    public function testConditionFieldDefaultsToNew(): void
    {
        // The Enum's schema default drives both the DB column default and the CMS dropdown preselect.
        $field = Product::singleton()->dbObject('Condition');
        $this->assertInstanceOf(DBEnum::class, $field);
        $this->assertSame('new', $field->getDefaultValue());
    }
}
