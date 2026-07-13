# Attributes

Which attributes a feed carries, and where each value comes from. Source of truth is the feed specs in `src/feeds/`.

## Platforms

| Platform | Fetched by | Image minimum |
|---|---|---|
| Google | Google Merchant Center | 500 x 500 |
| Meta | Meta Commerce Manager | 500 x 500 |
| Microsoft | Microsoft Merchant Center (Bing Shopping) | none documented |
| Pinterest | Pinterest catalogs | 1000 x 1500, portrait |
| TikTok | TikTok catalogs | 500 x 500, square |

Every platform takes the same RSS document. A feed's platform changes which attributes it carries and how they are worded, not the format.

TikTok names the identifier `sku_id`. The mapping screen still calls it `id`; only the written document differs.

## Derived, not mapped

| Attribute | Variants | Entries |
|---|---|---|
| `id` | the variant SKU | the entry ID |
| `item_group_id` | the product ID | not sent |
| `price` | Commerce, logged-out | mapped, required |
| `sale_price` | Commerce, only when lower than `price` | not sent |
| `sale_price_effective_date` | Sales stores only, and only when the sale has both a start and an end date | not sent |
| `availability` | stock and availability | mapped, required |
| `identifier_exists` | `no` when `brand`, `gtin`, and `mpn` are all blank | same |

`identifier_exists` is sent to Google and Microsoft only. Meta, Pinterest, and TikTok have no such field.

## Required

The same seven everywhere: `id`, `title`, `description`, `link`, `image_link`, `availability`, `price`. A feed will not build until they are mapped or derived.

Meta and TikTok require `brand` and `condition` on top of those.

## Mapped

| Attribute | Required by | Max length | Notes |
|---|---|---|---|
| `title` | all | 150, Pinterest 500 | Plain text |
| `description` | all | 5000, Microsoft and Pinterest 10,000 | Markup stripped |
| `link` | all | | Must be absolute |
| `image_link` | all | | Any mapping whose value resolves to an absolute URL, usually an Assets field. A value that is not an absolute URL is dropped. The default value is an asset, used when the mapped field is empty. Both go through the feed's image engine |
| `additional_image_link` | none | | Up to 10. Defaults to assets two to eleven of the `image_link` field; can be mapped to an asset field of its own. Not offered on a Microsoft feed, which ignores it |
| `brand` | Meta, TikTok | 70 | Google and Microsoft treat it as conditional, via `identifier_exists`. Pinterest leaves it optional |
| `gtin` | none | | Never truncated |
| `mpn` | none | 70 | |
| `condition` | Meta, TikTok | | `new`, `refurbished`, or `used`. Usually a default value of `new` |
| `product_type` | none | 750, Pinterest 1000 | A Categories field sends the full path, `Shades > Roller Shades` |
| `google_product_category` | none | Microsoft 255 | |
| `custom_label_0` to `custom_label_4` | none | 100 | |

Each limit is the platform's own, and a value longer than it is truncated.

## Availability values

Google takes `in_stock` and `out_of_stock`. Meta, Microsoft, Pinterest, and TikTok all document the spaced form, `in stock` and `out of stock`, and never the underscore. A feed carries whichever form its platform expects.

`preorder` is accepted everywhere, but nothing in Commerce can derive it. An entry feed can map it as a default value.

A variant is `out_of_stock` when it is not available for purchase, when its stock is tracked and below one, or when the feed's store holds no stock record for it. A product that is disabled, or whose post date has not arrived, or whose expiry date has passed, is omitted from the feed entirely rather than marked out of stock.

## Not included

`shipping` and `tax`: configured at the account level on Google and Microsoft. See [troubleshooting](../user-guide/troubleshooting.md).

`expiration_date`: items expire roughly 30 days after the platform last fetched the feed.

## Format

RSS 2.0 with Google's namespace, `http://base.google.com/ns/1.0`, on the `g` prefix. Meta, Pinterest, and TikTok accept the same document, and Microsoft accepts an XML file only if it is already a Google-formatted one.

The feed is stored gzipped at `product-feeds/<token>.xml.gz` on the feed filesystem, and served by Craft at `/product-feeds/<handle>-<token>.xml.gz`. The same artifact is served uncompressed at `/product-feeds/<handle>-<token>.xml`.
