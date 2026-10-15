<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test double that gives a product a {@code hasAvailableStock()} method without pulling in {@code silvershop/stock},
 * so the stock-driven availability path can be exercised in isolation. The returned state is toggled through the
 * static {@see self::$available} so a single test can assert both in-stock and out-of-stock.
 *
 * @extends Extension<\SilverShop\Page\Product>
 */
class StockAvailabilityStub extends Extension implements TestOnly
{
    public static bool $available = true;

    public function hasAvailableStock(int $require = 1): bool
    {
        return self::$available;
    }
}
