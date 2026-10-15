<?php

declare(strict_types=1);

namespace SilverShop\CatalogData;

/**
 * Product condition, as understood by the shopping channels (Google Merchant, Meta, …) and schema.org.
 *
 * The backing value is the lower-case token the channels expect ({@code new} / {@code refurbished} /
 * {@code used}); channels and SEO map it to their own vocabulary (e.g. schema.org's
 * {@code https://schema.org/NewCondition}). {@see \SilverShop\CatalogData\CatalogProductDataFactory} resolves it
 * from the {@code Condition} field that {@link \SilverShop\CatalogData\Extension\ProductConditionExtension} adds to
 * the product, defaulting to {@see self::New_}.
 */
enum Condition: string
{
    case New_ = 'new';
    case Refurbished = 'refurbished';
    case Used = 'used';

    /**
     * The default condition for a product that has not had one set (most catalogue items are new).
     */
    public static function default(): self
    {
        return self::New_;
    }

    /**
     * Resolve a stored/raw value to a case, falling back to {@see self::default()} for an empty or unknown value so
     * the mapper always yields a valid condition.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::default();
    }

    /**
     * The i18n entity key for this condition's human label (used for the CMS dropdown source).
     */
    public function labelKey(): string
    {
        return self::class . '.' . ucfirst($this->value);
    }
}
