# SilverShop Catalog Data

[![CI](https://github.com/silvershop/silvershop-catalog-data/actions/workflows/ci.yml/badge.svg)](https://github.com/silvershop/silvershop-catalog-data/actions/workflows/ci.yml)

The **shared, normalised product-data mapper** for [SilverShop](https://github.com/silvershop/silvershop) on
Silverstripe CMS 6. It turns a core `Product` or `Variation` into a single, format-agnostic value object —
`CatalogProductData` — carrying the channel-ready fields every outbound integration needs.

It is the **one source of truth** for product data in the suite. Rather than each module re-deriving title, price,
availability and identity from the catalogue, they all read the same VO:

| Consumer | Uses |
|---|---|
| `silvershop/product-feed-google` (and other feed channels) | maps the VO to `g:` RSS attributes; `feedId()` → `g:id` |
| `silvershop/seo` | formats the VO as schema.org Product JSON-LD |
| `silvershop/conversion-tracking` | `feedId()` → `content_ids`; `price()`/`currency()` → event value |

Because the feed's `g:id` and the ad platforms' `content_ids` **both** come from `feedId()`, catalogue feeds and
conversion events agree on product identity **by construction** — no mapping table, no drift.

## Requirements

- PHP 8.3+
- `silverstripe/framework` ^6.0, `silverstripe/cms` ^6.0
- `silvershop/core` ^6.2

That is the complete hard dependency set. Everything richer is a guarded soft dependency (see below).

## Installation

```bash
composer require silvershop/catalog-data
```

Then run `dev/build?flush=all` (adds the `Condition` field to `Product`).

## Usage

```php
use SilverShop\CatalogData\CatalogProductDataFactory;

$factory = CatalogProductDataFactory::create();

$data = $factory->forProduct($product);     // or ->forVariation($variation), or ->forBuyable($buyable)

$data->feedId();            // "RJ-001"            (the shared product id)
$data->title();             // "Rain Jacket"
$data->formattedPrice();    // "89.95 EUR"         (raw numeric + ISO-4217, channel-ready)
$data->formattedSalePrice();// "69.95 EUR" or null (only when a reduction is active)
$data->availability();      // Availability::InStock | OutOfStock | …
$data->imageLink();         // absolute URL of the primary image
$data->gtin();              // from silvershop/product-identity, or null
$data->condition();         // Condition::New_ | Refurbished | Used
$data->productType();       // "Clothing > Jackets"
$data->attributes();        // ['Color' => 'Red', 'Size' => 'L']  (variations)
```

The VO is immutable and format-agnostic: money is exposed both structured (`price()` float + `currency()` code) and
channel-ready (`formattedPrice()` → `"12.95 EUR"`, the SEO idiom, deliberately **not** a localised `Nice()` string).

## The `feedId()` contract (shared product id)

`CatalogProductDataFactory::feedId()` is the single definition of product identity across the suite. **It is a locked
contract** — the product feed's `g:id`/`g:item_group_id` and conversion tracking's `content_ids`/`item_group_id` are
derived from it, so it must stay stable.

- **Simple product:** its `InternalItemID` (SKU) when non-empty, otherwise `"product-{ID}"`.
- **Variation:** its own `InternalItemID` when non-empty, otherwise `"{parentFeedId}-{variationID}"`.
- **`item_group_id`** (for a variation) = the **parent product's** `feedId()`. A simple product has no group id.
- The result is **≤ 50 characters** (Google's limit; longer ids are truncated deterministically) and **stable**
  for a given record.

```php
$factory->feedId($product);   // "RJ-001"            (SKU present)
$factory->feedId($product);   // "product-12"        (no SKU → ID-based)
$factory->feedId($variation); // "RJ-001-RED"        (variation SKU present)
$factory->feedId($variation); // "RJ-001-34"         (no variation SKU → "{parentFeedId}-{variationID}")
$data->itemGroupId();         // "RJ-001"            (= parent feedId)
```

Configure the scheme with `id_source`:

```yaml
SilverShop\CatalogData\CatalogProductDataFactory:
  id_source: internal_item_id   # default: prefer the SKU, fall back to the ID
  # id_source: id               # always use the ID-based form, ignoring the SKU
  id_max_length: 50
```

## Fields and their sources

| VO field | Source | Guarded? |
|---|---|---|
| `feedId()` / `itemGroupId()` | `InternalItemID` + ID, per the contract above | no (core) |
| `title()` | `Product.Title` (variation appends its attribute title) | no (core) |
| `description()` | `MetaDescription`, else stripped `Content` (truncated) | no (core) |
| `link()` | `Product.AbsoluteLink()` (variation → parent) | no (core) |
| `imageLink()` / `additionalImageLinks()` | variation image → `getAllImages` (gallery) → `Image()` | **gallery guarded** |
| `availability()` | `hasAvailableStock()` (stock), else `canPurchase()` | **stock guarded** |
| `price()` / `salePrice()` / `currency()` | `BasePrice`/`Price` + `sellingPrice()` + site currency | no (core) |
| `brand()` | `silvershop/brands` `Brand` | **guarded** |
| `gtin()` / `mpn()` | `silvershop/product-identity` resolved accessors | **guarded** |
| `identifierExists()` | `false` only when no gtin + no mpn + no brand, else null | — |
| `condition()` | the `Condition` field this module adds (variation inherits product) | no (owned here) |
| `productType()` | primary category `NestedTitle()` path | no (core) |
| `attributes()` | variation `AttributeValues` (type label => value) | no (core) |

**Availability is stock-driven, never hardcoded:** when `silvershop/stock` is installed the enum follows
`hasAvailableStock()`; otherwise it follows `canPurchase()`. A buyable that cannot be purchased is always
`out_of_stock`.

## Soft dependencies (all guarded)

Each is read through `class_exists`/`hasMethod` guards, so the mapper works with or without it; absent values are
simply omitted:

- **`silvershop/product-identity`** — `gtin()` / `mpn()` (via its variant→product resolved accessors);
- **`silvershop/brands`** — `brand()`;
- **`silvershop/product-gallery`** — extra images in `additionalImageLinks()`;
- **`silvershop/colors`** — colour variant attributes surface through `attributes()`.

## The `Condition` field

This module adds a `Condition` enum (`new` / `refurbished` / `used`, default `new`) to `Product`, editable on the
Content tab. It is a catalogue/marketing attribute shared by the feed (`g:condition`), SEO JSON-LD (`itemCondition`)
and tracking, so it lives here beside the mapper rather than in the barcode-focused `silvershop/product-identity`.
Variations inherit their product's condition. Change the fallback with:

```yaml
SilverShop\CatalogData\CatalogProductDataFactory:
  default_condition: new   # new | refurbished | used
```

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
