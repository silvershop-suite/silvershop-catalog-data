<?php

declare(strict_types=1);

namespace SilverShop\CatalogData\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test double that gives a buyable {@code silvershop/stock}'s {@code getStockLevel()} accessor without pulling in
 * that module, so the catalog {@code quantity()} read can be exercised in isolation. The returned level is toggled
 * through the static {@see self::$level}: a numeric string is the finite quantity, {@code "-1"} is the unlimited
 * sentinel, and {@code null} is an untracked record.
 *
 * @extends Extension<\SilverShop\Page\Product>
 */
class StockLevelStub extends Extension implements TestOnly
{
    public static ?string $level = null;

    public function getStockLevel(): ?string
    {
        return self::$level;
    }
}
