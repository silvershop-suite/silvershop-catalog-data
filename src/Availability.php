<?php

declare(strict_types=1);

namespace SilverShop\CatalogData;

/**
 * Stock availability, as understood by the shopping channels (Google Merchant, Meta, …).
 *
 * The backing value is the channel token ({@code in_stock} / {@code out_of_stock} / {@code preorder} /
 * {@code backorder}); a channel maps it to its own vocabulary and SEO maps it to schema.org
 * ({@code https://schema.org/InStock} etc.).
 *
 * {@see \SilverShop\CatalogData\CatalogProductDataFactory} derives {@see self::InStock} / {@see self::OutOfStock}
 * from the shop's real stock state (the {@code hasAvailableStock()} signal from {@code silvershop/stock}, falling
 * back to {@code canPurchase()}). {@see self::Preorder} / {@see self::Backorder} are part of the vocabulary for
 * channels and future modules to set explicitly — core carries no pre-order/backorder signal, so the factory never
 * derives them.
 */
enum Availability: string
{
    case InStock = 'in_stock';
    case OutOfStock = 'out_of_stock';
    case Preorder = 'preorder';
    case Backorder = 'backorder';
}
