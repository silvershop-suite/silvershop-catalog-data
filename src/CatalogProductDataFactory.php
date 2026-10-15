<?php

declare(strict_types=1);

namespace SilverShop\CatalogData;

use InvalidArgumentException;
use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Model\Buyable;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * The canonical mapper that normalises a SilverShop {@link Product} or {@link Variation} into a channel-ready
 * {@link CatalogProductData} value object. This is the suite's one source of truth for product data — the product
 * feed, SEO JSON-LD and conversion tracking all build their output from the VO this factory produces, so they agree
 * on identity, price and availability by construction.
 *
 * The guarded extraction helpers ({@see self::callMethod()}, {@see self::imageUrls()}, {@see self::prune()}) are
 * lifted from {@code silvershop/seo}'s structured-data providers and live here as the canonical implementation: soft
 * dependencies ({@code silvershop/product-identity} for GTIN/MPN, {@code silvershop/brands} for the brand,
 * {@code silvershop/stock} for stock-driven availability, {@code silvershop/product-gallery} for extra images) are
 * read defensively so the mapper works whether or not they are installed.
 */
class CatalogProductDataFactory
{
    use Injectable;
    use Configurable;

    /**
     * {@code id_source}: derive {@see self::feedId()} from the record's {@code InternalItemID} (SKU) when present,
     * falling back to an {@code ID}-based id. The default and recommended scheme.
     */
    public const ID_SOURCE_INTERNAL = 'internal_item_id';

    /**
     * {@code id_source}: always derive {@see self::feedId()} from the record {@code ID}, ignoring the SKU.
     */
    public const ID_SOURCE_ID = 'id';

    private const PRODUCT_PREFIX = 'product-';

    private const CATEGORY_SEPARATOR = ' > ';

    /**
     * Which scheme {@see self::feedId()} uses: self::ID_SOURCE_INTERNAL (default) or self::ID_SOURCE_ID.
     */
    private static string $id_source = self::ID_SOURCE_INTERNAL;

    /**
     * Hard cap on a feed id's length (Google's {@code id}/{@code item_group_id} limit). Ids longer than this are
     * truncated deterministically. 0 disables the cap.
     */
    private static int $id_max_length = 50;

    /**
     * Maximum number of additional images exposed (Google allows up to 10 on top of the primary image). 0 = no cap.
     */
    private static int $image_limit = 10;

    /**
     * Maximum description length (Google's {@code description} cap is 5000). Longer text is truncated with an
     * ellipsis. 0 = no cap.
     */
    private static int $description_max_length = 5000;

    /**
     * The condition applied to a product that has no explicit {@code Condition} value. One of the
     * {@see Condition} tokens ({@code new} / {@code refurbished} / {@code used}).
     */
    private static string $default_condition = 'new';

    /**
     * Build the VO from any buyable, dispatching on its concrete type.
     *
     * @throws InvalidArgumentException when the buyable is neither a Product nor a Variation
     */
    public function forBuyable(Buyable $buyable): CatalogProductData
    {
        if ($buyable instanceof Variation) {
            return $this->forVariation($buyable);
        }

        if ($buyable instanceof Product) {
            return $this->forProduct($buyable);
        }

        throw new InvalidArgumentException(sprintf(
            '%s cannot map %s; expected a %s or %s.',
            self::class,
            $buyable::class,
            Product::class,
            Variation::class
        ));
    }

    public function forProduct(Product $product): CatalogProductData
    {
        [$price, $salePrice] = $this->priceAndSale($product);
        $images = $this->collectImageUrls($product);
        $brand = $this->brandFor($product);
        $gtin = $this->resolvedGtin($product);
        $mpn = $this->resolvedMpn($product);

        return new CatalogProductData(
            $this->feedId($product),
            null,
            (string) $product->getField('Title'),
            $this->description($product),
            (string) $product->AbsoluteLink(),
            $images[0] ?? null,
            $this->additionalImages($images),
            $this->availabilityFor($product),
            $price,
            $salePrice,
            $this->currency(),
            $brand,
            $gtin,
            $mpn,
            $this->identifierExists($gtin, $mpn, $brand),
            $this->conditionFor($product),
            $this->productTypeFor($product),
            [],
        );
    }

    public function forVariation(Variation $variation): CatalogProductData
    {
        $parent = $variation->Product();
        $hasParent = $parent instanceof Product && $parent->exists();

        [$price, $salePrice] = $this->priceAndSale($variation);
        $images = $this->collectImageUrls($variation);
        $brand = $hasParent ? $this->brandFor($parent) : null;
        $gtin = $this->resolvedGtin($variation);
        $mpn = $this->resolvedMpn($variation);

        $baseTitle = $hasParent ? (string) $parent->getField('Title') : '';
        $variantTitle = trim((string) $variation->getTitle());
        $title = $variantTitle !== '' ? trim($baseTitle . ' - ' . $variantTitle) : $baseTitle;

        return new CatalogProductData(
            $this->feedId($variation),
            $hasParent ? $this->feedId($parent) : null,
            $title,
            $hasParent ? $this->description($parent) : '',
            $hasParent ? (string) $parent->AbsoluteLink() : '',
            $images[0] ?? null,
            $this->additionalImages($images),
            $this->availabilityFor($variation),
            $price,
            $salePrice,
            $this->currency(),
            $brand,
            $gtin,
            $mpn,
            $this->identifierExists($gtin, $mpn, $brand),
            $hasParent ? $this->conditionFor($parent) : Condition::fromValue($this->configuredDefaultCondition()),
            $hasParent ? $this->productTypeFor($parent) : null,
            $this->attributesFor($variation),
        );
    }

    /**
     * The stable, shared product id — the single definition of product identity across the feed ({@code g:id}) and
     * conversion tracking ({@code content_ids}). The rule (locked contract):
     *
     *  - simple product: its {@code InternalItemID} (SKU) when non-empty, else {@code "product-{ID}"};
     *  - variation: its own {@code InternalItemID} when non-empty, else {@code "{parentFeedId}-{variationID}"};
     *  - with {@code id_source: id} the SKU is ignored and the {@code ID}-based form is always used;
     *  - the result is truncated to {@code id_max_length} (50) characters.
     *
     * A variation's {@code item_group_id} is the parent product's feed id (see {@see self::forVariation()}), so a
     * variant and its group always share a prefix. The scheme is deterministic and stable for a given record.
     */
    public function feedId(DataObject $record): string
    {
        return $this->truncateId($this->composeId($record));
    }

    private function composeId(DataObject $record): string
    {
        $useInternal = static::config()->get('id_source') !== self::ID_SOURCE_ID;

        if ($record instanceof Variation) {
            $own = trim((string) $record->getField('InternalItemID'));
            if ($useInternal && $own !== '') {
                return $own;
            }

            $parent = $record->Product();
            $base = ($parent instanceof Product && $parent->exists())
                ? $this->feedId($parent)
                : self::PRODUCT_PREFIX . '0';

            return $base . '-' . $record->ID;
        }

        $own = trim((string) $record->getField('InternalItemID'));
        if ($useInternal && $own !== '') {
            return $own;
        }

        return self::PRODUCT_PREFIX . $record->ID;
    }

    private function truncateId(string $id): string
    {
        $max = (int) static::config()->get('id_max_length');

        return ($max > 0 && strlen($id) > $max) ? substr($id, 0, $max) : $id;
    }

    /**
     * Regular + sale price. When the effective selling price is strictly below the list price a reduction is
     * active: the list price is the regular price and the selling price is the sale price. Otherwise the selling
     * price (or list price when there is no selling price) is the regular price and there is no sale. A
     * non-positive regular price is exposed as {@code null} (channels require a positive price).
     *
     * @return array{0: float|null, 1: float|null} [regular price, sale price]
     */
    private function priceAndSale(Product|Variation $record): array
    {
        $selling = round((float) $record->sellingPrice(), 2);
        $regular = round($this->listPrice($record), 2);

        if ($regular > 0.0 && $selling > 0.0 && $selling < $regular) {
            $price = $regular;
            $sale = $selling;
        } else {
            $price = $selling > 0.0 ? $selling : $regular;
            $sale = null;
        }

        return [$price > 0.0 ? $price : null, $sale];
    }

    /**
     * The raw list price: a product's {@code BasePrice}, a variation's own {@code Price} (0 when it inherits the
     * product's base price, in which case the selling price stands in).
     */
    private function listPrice(Product|Variation $record): float
    {
        return (float) $record->getField($record instanceof Variation ? 'Price' : 'BasePrice');
    }

    /**
     * Stock-driven availability: {@code in_stock}/{@code out_of_stock} from {@code silvershop/stock}'s
     * {@code hasAvailableStock()} when installed, otherwise from {@code canPurchase()}. Never hardcoded. A buyable
     * that cannot be purchased at all is always out of stock.
     */
    private function availabilityFor(Product|Variation $record): Availability
    {
        if (!$record->canPurchase()) {
            return Availability::OutOfStock;
        }

        if ($record->hasMethod('hasAvailableStock')) {
            return (bool) $this->callMethod($record, 'hasAvailableStock')
                ? Availability::InStock
                : Availability::OutOfStock;
        }

        return Availability::InStock;
    }

    private function currency(): string
    {
        return ShopConfigExtension::get_site_currency();
    }

    private function conditionFor(Product $product): Condition
    {
        $raw = trim((string) $product->getField('Condition'));

        return Condition::fromValue($raw !== '' ? $raw : $this->configuredDefaultCondition());
    }

    private function configuredDefaultCondition(): string
    {
        return (string) static::config()->get('default_condition');
    }

    /**
     * The shop's own category path for a product, e.g. {@code "Clothing > Jackets"} (Google {@code product_type}),
     * taken from the product's primary category (its parent in the tree, else its first assigned category). Null
     * when the product is not categorised.
     */
    private function productTypeFor(Product $product): ?string
    {
        $category = null;
        $parent = $product->Parent();
        if ($parent instanceof ProductCategory && $parent->exists()) {
            $category = $parent;
        } elseif ($product->getCategoryIDs() !== []) {
            // Guarded: getCategories() does byIDs($ids), and in SS6 byIDs([]) throws "filter against an empty set".
            $first = $product->getCategories()->first();
            if ($first instanceof ProductCategory) {
                $category = $first;
            }
        }

        if (!$category instanceof ProductCategory || !$category->hasMethod('NestedTitle')) {
            return null;
        }

        // NestedTitle walks the category's parent chain; filter out any empty segments (e.g. the site root) so the
        // path never carries leading separators.
        $raw = (string) $category->NestedTitle(10, self::CATEGORY_SEPARATOR, 'MenuTitle');
        $parts = array_values(array_filter(
            array_map('trim', explode(self::CATEGORY_SEPARATOR, $raw)),
            static fn (string $part): bool => $part !== ''
        ));

        return $parts === [] ? null : implode(self::CATEGORY_SEPARATOR, $parts);
    }

    /**
     * Variant attributes as label => value (e.g. ['Color' => 'Red', 'Size' => 'L']), keyed by the attribute type's
     * front-end Label (falling back to its Name). Empty for a product with no attribute values.
     *
     * @return array<string, string>
     */
    private function attributesFor(Variation $variation): array
    {
        $attributes = [];

        foreach ($variation->AttributeValues() as $value) {
            $type = $value->Type();
            $label = ($type && $type->exists())
                ? (string) ($type->getField('Label') ?: $type->getField('Name'))
                : '';
            $attributes[$label] = (string) $value->getField('Value');
        }

        unset($attributes['']);

        return $this->prune($attributes);
    }

    private function brandFor(Product $product): ?string
    {
        $brand = $this->callMethod($product, 'Brand');
        if (!$brand instanceof DataObject || !$brand->exists()) {
            return null;
        }

        $title = trim((string) $brand->getField('Title'));

        return $title !== '' ? $title : null;
    }

    /**
     * Resolved GTIN from {@code silvershop/product-identity} (which owns the variant→product fallback), or null when
     * that module is absent or no code is set.
     */
    private function resolvedGtin(DataObject $record): ?string
    {
        if (!$record->hasMethod('getResolvedGtin')) {
            return null;
        }

        $gtin = trim((string) $this->callMethod($record, 'getResolvedGtin'));

        return $gtin !== '' ? $gtin : null;
    }

    private function resolvedMpn(DataObject $record): ?string
    {
        if (!$record->hasMethod('getResolvedMpn')) {
            return null;
        }

        $mpn = trim((string) $this->callMethod($record, 'getResolvedMpn'));

        return $mpn !== '' ? $mpn : null;
    }

    /**
     * {@code identifier_exists: no} (false) only when there is no GTIN, no MPN and no brand; null otherwise so the
     * attribute is omitted and the channel default ({@code yes}) stands.
     */
    private function identifierExists(?string $gtin, ?string $mpn, ?string $brand): ?bool
    {
        return ($gtin === null && $mpn === null && $brand === null) ? false : null;
    }

    private function description(Product $product): string
    {
        $meta = trim((string) $product->getField('MetaDescription'));
        if ($meta !== '') {
            return $this->truncateText($meta);
        }

        $content = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $product->getField('Content'))));

        return $this->truncateText($content);
    }

    private function truncateText(string $text): string
    {
        $max = (int) static::config()->get('description_max_length');
        if ($max > 0 && mb_strlen($text) > $max) {
            return rtrim(mb_substr($text, 0, $max - 1)) . '…';
        }

        return $text;
    }

    /**
     * Absolute image URLs for a buyable: a variation's own image first, then the parent product's gallery/primary
     * image; a product's gallery/primary image.
     *
     * @return array<int, string>
     */
    private function collectImageUrls(Product|Variation $record): array
    {
        if (!$record instanceof Variation) {
            return $this->imageUrls($record);
        }

        $urls = [];
        $own = $record->Image();
        if ($own instanceof Image && $own->exists()) {
            $url = (string) $own->getAbsoluteURL();
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        $parent = $record->Product();
        if ($parent instanceof Product && $parent->exists()) {
            foreach ($this->imageUrls($parent) as $url) {
                if (!in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Additional-image URLs (primary excluded), capped at {@code image_limit}.
     *
     * @param array<int, string> $images
     * @return array<int, string>
     */
    private function additionalImages(array $images): array
    {
        $extra = array_slice($images, 1);
        $limit = (int) static::config()->get('image_limit');
        if ($limit > 0 && count($extra) > $limit) {
            $extra = array_slice($extra, 0, $limit);
        }

        return array_values($extra);
    }

    /**
     * Call $method on $object when it exists (including extension-provided methods), else return $default. The
     * defensive caller for soft-dependency methods not known to static analysis.
     *
     * @param array<int, mixed> $args
     */
    private function callMethod(?object $object, string $method, mixed $default = null, array $args = []): mixed
    {
        if ($object !== null && $object->hasMethod($method)) {
            return call_user_func_array([$object, $method], $args);
        }

        return $default;
    }

    /**
     * Absolute URLs of a record's images, preferring the full gallery ({@code silvershop/product-gallery}'s
     * getAllImages) and falling back to the single primary Image(). Non-existent images are skipped and duplicates
     * removed.
     *
     * @return array<int, string>
     */
    private function imageUrls(DataObject $record, int $limit = 0): array
    {
        $images = [];

        $all = $this->callMethod($record, 'getAllImages');
        if (is_iterable($all)) {
            foreach ($all as $image) {
                $images[] = $image;
            }
        } else {
            $primary = $this->callMethod($record, 'Image');
            if ($primary !== null) {
                $images[] = $primary;
            }
        }

        $urls = [];
        foreach ($images as $image) {
            if ($image instanceof Image && $image->exists()) {
                $url = (string) $image->getAbsoluteURL();
                if ($url !== '' && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
            if ($limit > 0 && count($urls) >= $limit) {
                break;
            }
        }

        return $urls;
    }

    /**
     * Drop empty values ('', null, []) from an array, so blank fields are simply omitted.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prune(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== '' && $value !== null && $value !== []);
    }
}
