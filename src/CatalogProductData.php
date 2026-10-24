<?php

declare(strict_types=1);

namespace SilverShop\CatalogData;

/**
 * Immutable, format-agnostic value object describing one sellable catalogue item (a {@code Product} or a
 * {@code Variation}) with the fields the shopping channels need.
 *
 * This is the suite's single normalised product-data representation. {@link CatalogProductDataFactory} builds it
 * from a buyable; the consumers only *format* it:
 *
 *  - {@code silvershop/product-feed-google} maps it to {@code g:} RSS attributes;
 *  - {@code silvershop/seo} formats it as schema.org JSON-LD;
 *  - {@code silvershop/conversion-tracking} reads {@see self::feedId()} for {@code content_ids} and
 *    {@see self::price()} / {@see self::currency()} for the event value.
 *
 * Because every consumer reads {@see self::feedId()}, the feed's {@code g:id} and the ad platforms'
 * {@code content_ids} agree by construction.
 *
 * Money is exposed both structured ({@see self::price()} float + {@see self::currency()} code) and channel-ready
 * ({@see self::formattedPrice()} → {@code "12.95 EUR"}); the latter is the raw numeric + ISO-4217 form the channels
 * want, deliberately NOT a localised {@code Nice()} string.
 */
final class CatalogProductData
{
    /**
     * The trailing arguments ({@see self::quantity()} onward) are later, purely additive extensions carrying the
     * fields a marketplace-oriented feed (e.g. Channable) wants beyond the text/marketing set. They default so every
     * existing positional caller keeps working unchanged.
     *
     * @param array<int, string>    $additionalImageLinks absolute URLs, primary image excluded, in order
     * @param array<string, string> $attributes           variant attribute label => value (e.g. ['Color' => 'Red'])
     * @param array<int, string>    $secondaryCategories  additional category paths beyond the primary productType
     */
    public function __construct(
        private readonly string $feedId,
        private readonly ?string $itemGroupId,
        private readonly string $title,
        private readonly string $description,
        private readonly string $link,
        private readonly ?string $imageLink,
        private readonly array $additionalImageLinks,
        private readonly Availability $availability,
        private readonly ?float $price,
        private readonly ?float $salePrice,
        private readonly string $currency,
        private readonly ?string $brand,
        private readonly ?string $gtin,
        private readonly ?string $mpn,
        private readonly ?bool $identifierExists,
        private readonly Condition $condition,
        private readonly ?string $productType,
        private readonly array $attributes,
        private readonly ?int $quantity = null,
        private readonly ?float $weight = null,
        private readonly ?float $height = null,
        private readonly ?float $width = null,
        private readonly ?float $depth = null,
        private readonly array $secondaryCategories = [],
        private readonly ?string $lastModified = null,
    ) {
    }

    /**
     * The stable, shared product identifier (≤50 chars). This is the feed's {@code g:id} and the ad platforms'
     * {@code content_ids}; see {@link CatalogProductDataFactory::feedId()} for the exact rule.
     */
    public function feedId(): string
    {
        return $this->feedId;
    }

    /**
     * The variant group id — the parent product's {@see self::feedId()} — for a variation, or {@code null} for a
     * simple product. Maps to Google's {@code item_group_id}.
     */
    public function itemGroupId(): ?string
    {
        return $this->itemGroupId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * Absolute canonical URL for the item (a variation resolves to its parent product's page).
     */
    public function link(): string
    {
        return $this->link;
    }

    /**
     * Absolute URL of the primary image, or {@code null} when the item has no usable image.
     */
    public function imageLink(): ?string
    {
        return $this->imageLink;
    }

    /**
     * Absolute URLs of the additional images (primary excluded), in order.
     *
     * @return array<int, string>
     */
    public function additionalImageLinks(): array
    {
        return $this->additionalImageLinks;
    }

    public function availability(): Availability
    {
        return $this->availability;
    }

    /**
     * The regular (list) price as a float, or {@code null} when unknown. When a reduction is active this is the
     * higher, struck-through price and {@see self::salePrice()} holds the reduced one.
     */
    public function price(): ?float
    {
        return $this->price;
    }

    /**
     * The reduced price as a float when a reduction is active, else {@code null}.
     */
    public function salePrice(): ?float
    {
        return $this->salePrice;
    }

    /**
     * ISO-4217 currency code for {@see self::price()} / {@see self::salePrice()}.
     */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Channel-ready regular price, e.g. {@code "12.95 EUR"} (raw numeric, dot decimal, ISO-4217 code), or
     * {@code null} when there is no price. This is the seo idiom, NOT a localised currency string.
     */
    public function formattedPrice(): ?string
    {
        return $this->formatMoney($this->price);
    }

    /**
     * Channel-ready sale price, e.g. {@code "9.95 EUR"}, or {@code null} when no reduction is active.
     */
    public function formattedSalePrice(): ?string
    {
        return $this->formatMoney($this->salePrice);
    }

    public function brand(): ?string
    {
        return $this->brand;
    }

    /**
     * Resolved GTIN (digits-only) from {@code silvershop/product-identity}, or {@code null} when that module is
     * absent or the item has none.
     */
    public function gtin(): ?string
    {
        return $this->gtin;
    }

    /**
     * Resolved MPN from {@code silvershop/product-identity}, or {@code null} when absent.
     */
    public function mpn(): ?string
    {
        return $this->mpn;
    }

    /**
     * {@code false} only when the item has no GTIN, no MPN and no brand (Google's {@code identifier_exists: no});
     * {@code null} otherwise, so the attribute is simply omitted and the channel default ({@code yes}) applies.
     */
    public function identifierExists(): ?bool
    {
        return $this->identifierExists;
    }

    public function condition(): Condition
    {
        return $this->condition;
    }

    /**
     * The shop's own category path (e.g. {@code "Clothing > Jackets"}), or {@code null} when the item is not
     * categorised. Maps to Google's {@code product_type}.
     */
    public function productType(): ?string
    {
        return $this->productType;
    }

    /**
     * Variant attributes as label => value (e.g. {@code ['Color' => 'Red', 'Size' => 'L']}); empty for a simple
     * product. A channel picks out {@code color} / {@code size} as it needs.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Finite on-hand stock quantity (from {@code silvershop/stock}), or {@code null} when stock is untracked,
     * unlimited, or that module is absent — in which case {@see self::availability()} is the only stock signal.
     */
    public function quantity(): ?int
    {
        return $this->quantity;
    }

    /**
     * Shipping weight in the shop's configured unit, or {@code null} when unset. For marketplace size/rate rules.
     */
    public function weight(): ?float
    {
        return $this->weight;
    }

    /**
     * Shipping height in the shop's configured unit, or {@code null} when unset.
     */
    public function height(): ?float
    {
        return $this->height;
    }

    /**
     * Shipping width in the shop's configured unit, or {@code null} when unset.
     */
    public function width(): ?float
    {
        return $this->width;
    }

    /**
     * Shipping depth in the shop's configured unit, or {@code null} when unset.
     */
    public function depth(): ?float
    {
        return $this->depth;
    }

    /**
     * Additional category paths the item is listed in, beyond the single primary {@see self::productType()} path
     * (e.g. {@code ['Shop > Sale', 'Shop > New in']}); empty when there are none. Each is a full {@code "A > B"}
     * path, the primary path excluded.
     *
     * @return array<int, string>
     */
    public function secondaryCategories(): array
    {
        return $this->secondaryCategories;
    }

    /**
     * ISO-8601 timestamp of the item's last change (its {@code LastEdited}, for a variation the later of its own
     * and its product's), or {@code null} when unavailable. Useful for a channel's delta/refresh logic.
     */
    public function lastModified(): ?string
    {
        return $this->lastModified;
    }

    private function formatMoney(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format($value, 2, '.', '') . ' ' . $this->currency;
    }
}
