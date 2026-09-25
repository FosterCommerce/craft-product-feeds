# Attributes

Which attributes a feed sends, and where each value comes from. The feed specs in `src/feeds/` define them.

## Platforms

| Platform | Fetched by | Image minimum |
|---|---|---|
| Google | Google Merchant Center | 500 by 500 |
| Klaviyo | Klaviyo, as a Catalog Source | none documented |
| Meta | Meta Commerce Manager | 500 by 500 |
| Microsoft | Microsoft Merchant Center (Bing Shopping) | none documented |
| Pinterest | Pinterest catalogs | 1000 by 1500, portrait |
| TikTok | TikTok catalogs | 500 by 500, square |

The five shopping platforms take the same RSS document. A feed's platform changes which attributes it sends and how they are worded, not the format. Klaviyo is the exception: it takes a JSON document with its own attributes, and has a [section of its own](#klaviyo) below.

TikTok names the identifier `sku_id`. The **Mapping** tab labels it `id`; only the written document uses `sku_id`.

## Derived, not mapped

| Attribute | Variants | Entries |
|---|---|---|
| `id` | the variant SKU | the entry ID |
| `item_group_id` | the product ID | mapped |
| `price` | Commerce, logged-out | mapped, required |
| `sale_price` | Commerce, only when lower than `price` | mapped |
| `sale_price_effective_date` | Sales stores only, and only when the sale has both a start and an end date | mapped, as `start/end` in ISO 8601, such as `2026-11-27T00:00-0500/2026-12-01T23:59-0500` |
| `availability` | stock and availability | mapped, required |
| `inventory_quantity` | stock, on a Klaviyo feed only, and only when the variant tracks it | mapped |
| `identifier_exists` | `no` when `brand`, `gtin`, and `mpn` are all blank | same |

`identifier_exists` is sent to Google and Microsoft only. Meta, Pinterest, and TikTok have no such field.

On a custom source's feed, the attributes its `computedAttributes()` lists are derived, and the rest are mapped.

## Required

The same seven on every shopping platform: `id`, `title`, `description`, `link`, `image_link`, `availability`, `price`. A feed does not build until they are mapped or derived.

Meta and TikTok require `brand` and `condition` on top of those. Klaviyo requires five: `id`, `title`, `description`, `link`, and `image_link`.

## Mapped

| Attribute | Required by | Max length | Notes |
|---|---|---|---|
| `title` | all | 150, Pinterest 500 | Plain text |
| `description` | all | 5000, Microsoft and Pinterest 10,000 | Markup stripped |
| `link` | all | | Must be an absolute `http` or `https` URL |
| `image_link` | all | | An Assets field or a Twig value. A relative URL is resolved against the site's domain, not any subdirectory in its base URL. A URL that isn't `http` or `https` is discarded. The default value is an asset, used when the mapped field is empty. The feed's image engine transforms both |
| `additional_image_link` | none | | Up to 10. Defaults to assets two to eleven of the `image_link` field; can be mapped to an Assets field of its own. Not offered on a Microsoft feed, since Microsoft ignores the attribute |
| `brand` | Meta, TikTok | 70 | Google and Microsoft treat it as conditional, via `identifier_exists`. Pinterest leaves it optional |
| `gtin` | none | | Never truncated |
| `mpn` | none | 70 | |
| `condition` | Meta, TikTok | | `new`, `refurbished`, or `used`. Usually a default value of `new` |
| `product_type` | none | 750, Pinterest 1000 | A Categories field sends the full path, `Apparel > Shirts` |
| `google_product_category` | none | Microsoft 255 | |
| `color` | none | 100 | |
| `size` | none | 100 | |
| `material` | none | 200 | |
| `pattern` | none | 100 | |
| `availability_date` | none | | Google only. See [availability values](#availability-values) |
| `custom_label_0` to `custom_label_4` | none | 100 | |

`color`, `size`, `material`, and `pattern` distinguish items that share an `item_group_id`.

Each limit is the platform's own, and the plugin truncates a longer value.

## Availability values

Google takes `in_stock` and `out_of_stock`. Meta, Microsoft, Pinterest, and TikTok all document the spaced form, `in stock` and `out of stock`. Each feed sends the form its platform documents.

Every shopping platform accepts `preorder`. The plugin does not derive it from Commerce; an entry feed can map it as a default value.

Only a Google feed offers `backorder`, for an item that ships once restocked. The other platforms do not document the value.

With `preorder` or `backorder`, Google requires `availability_date`: the date the item ships, as ISO 8601, such as `2026-12-25T13:00-0800`. A Twig value can compute it, such as `{{ now|date_modify('+14 days')|date('Y-m-d\\TH:iO') }}`.

A variant is `out_of_stock` when it is not available for purchase, or when the feed's store has no stock record for it. A tracked variant with stock below one is `in_stock` if Commerce still allows the purchase, and `out_of_stock` otherwise. The feed omits a product that is disabled, not yet posted, or expired, rather than marking it out of stock.

## Klaviyo

A Klaviyo feed is a custom catalog, added in Klaviyo under **Catalog -> Sources**. It has no availability string, additional images, brand, or condition, and sends stock as a number.

| Attribute | Sent as | Required | Notes |
|---|---|---|---|
| `id` | `$id` | yes | The variant SKU |
| `title` | `$title` | yes | Plain text, truncated at 150 characters |
| `description` | `$description` | yes | Markup stripped, truncated at 5000 characters |
| `link` | `$link` | yes | Must be absolute |
| `image_link` | `$image_link` | yes | One image per item |
| `price` | `$price` | no | A JSON number with no currency code. Klaviyo takes the currency from your account |
| `categories` | `categories` | no | A JSON array. Every value the mapped field holds, each one a category in its own right rather than a path |
| `inventory_quantity` | `$inventory_quantity` | no | A JSON number. Left off an item whose inventory Commerce does not track, because Klaviyo treats zero as out of stock |
| `inventory_policy` | `$inventory_policy` | no | What Klaviyo does with an item at zero stock. `1` hides it from product blocks and recommendations, and is what a back in stock flow reads. `0` and `2` both keep showing it. Set it as a default value |

Klaviyo names its own fields with a `$` prefix. The **Mapping** tab uses their plain names; only the written document differs. Klaviyo keeps any field it does not recognize as custom metadata.

## Not included

`shipping` and `tax`: configured at the account level on Google and Microsoft. To set them, see [troubleshooting](../user-guide/troubleshooting.md#every-item-is-disapproved-for-missing-shipping).

`expiration_date`: items expire roughly 30 days after the platform last fetched the feed.

## Format

Google, Meta, Microsoft, Pinterest, and TikTok take RSS 2.0 with Google's namespace, `http://base.google.com/ns/1.0`, on the `g` prefix. Microsoft accepts an XML file only if it is already a Google-formatted one.

Klaviyo takes a JSON array of items, one node deep.

The feed is stored gzipped at `product-feeds/<token>.<extension>.gz` on the feed filesystem, and served by Craft at `/product-feeds/<handle>-<token>.<extension>.gz`. The same artifact is served uncompressed at `/product-feeds/<handle>-<token>.<extension>`. The extension is `xml` on a shopping feed and `json` on a Klaviyo one.
