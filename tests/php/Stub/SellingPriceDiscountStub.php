<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test double that scales a buyable's selling price through the core {@code updateSellingPrice} hook, so a reduction
 * (selling price below the list/base price) can be simulated without a discounts module. A factor of 1.0 (the
 * default) is a no-op, so the extension can be applied to a whole test class and only the reduction test flips it.
 *
 * @extends Extension<\SilverShop\Page\Product>
 */
class SellingPriceDiscountStub extends Extension implements TestOnly
{
    public static float $factor = 1.0;

    public function updateSellingPrice(float &$price): void
    {
        $price *= self::$factor;
    }
}
