# Mapping a feed

How to connect Craft data to a platform's attributes. Audience: store admins setting up a feed.

## Choosing what the feed reads

On the **Settings** tab, a variant feed lists your **Product types** and an entry feed lists your **Entry types**, grouped under the section each one belongs to. Leave everything unchecked to include every one that can work.

An entry type can be used by more than one section, so it is listed once per section. Ticking "General Page" under **Pages** feeds only the Pages entries of that type, and leaves the same entry type alone wherever else it is used.

Singles are not listed, nor is anything without a public URL on this site.

What you pick here decides which fields the mapping screen offers.

## The mapping screen

Every attribute the platform defines gets a row on the **Mapping** tab, apart from the ones the plugin fills in for you. Each row asks where its value comes from:

- **Don't include**: the attribute is left out. A required attribute has no **Don't include**; its row starts blank until you choose a source.
- **Use default value**: the same value for every item, set in the Default value column. Right for `condition`, and for `brand` on a single-brand store. On `image_link` it is an asset picker rather than a text box.
- **Variant properties** / **Product properties**, or **Entry properties** on an entry feed: a native value such as the SKU, the product title, or the product URL.
- **Variant fields** and **Product fields**, or **Entry fields**: a Craft field. On a variant feed the variant's own fields and its product's fields are listed separately.

Each attribute offers a fixed set of field types, and nothing else appears in its dropdown. Text attributes take a Plain Text, Number, Dropdown, Radio Buttons, Checkboxes, Lightswitch, Email, or Categories field. `image_link` and `additional_image_link` take an Assets field. `price` and `sale_price` take a Number or Plain Text field. `product_type` takes a Categories or Plain Text field. Every other field type, including Matrix, Table, Date, Link, Money, Entries, Tags, Users, and Color, and anything a third-party plugin adds, is left out.

On an entry feed, only fields on the entry types you selected are listed. Select another entry type, save, and its fields appear in the dropdown on the reloaded screen.

Fields are listed under the name they carry on that layout, not their global name. One field reused twice under different handles, say `previewImage` and `seoImage`, appears twice.

## Required attributes

Every shopping platform requires `id`, `title`, `description`, `link`, `image_link`, `availability`, and `price`. A feed will not build until they are mapped or derived.

Meta and TikTok additionally require `brand` and `condition`. Google, Microsoft, and Pinterest require neither.

A **Klaviyo feed** requires five: `id`, `title`, `description`, `link`, and `image_link`. It has no `availability`: stock reaches Klaviyo as `inventory_quantity`, a number, and `inventory_policy` says what Klaviyo does with an item once that number is zero. See [attributes](../reference/attributes.md#klaviyo).

On a **variant feed** the plugin derives `id`, `item_group_id`, `price`, `sale_price`, `sale_price_effective_date`, `availability`, and `inventory_quantity` from Commerce, so they never appear as rows. Google and Microsoft feeds derive `identifier_exists` as well. That leaves `title`, `description`, `link`, and `image_link`, plus `brand` and `condition` on a Meta or TikTok feed.

On an **entry feed** there is no Commerce variant behind the item, so `price` and `availability` are yours to map, and are required.

A new feed arrives part-mapped: `title` and `link` point at the element's title and URL, and `condition` defaults to `new`. An entry feed also starts with `availability` defaulted to `in_stock`.

## Filtering a feed

The **Filter** on the Settings tab is Craft's own condition builder, the same one you use on an element index. Add rules to narrow the feed to a subset of its source, so one product type or entry type can drive several feeds: one for a brand, one for a category, one for everything on promotion.

## Previewing

Once a feed is saved, **Preview items** at the bottom of the Mapping tab shows the first few items as the feed would carry them, and says which ones would be excluded and why. It quotes logged-out prices.

The preview reads the feed's saved mapping, not the one on screen. Save your changes before previewing.

## Data issues

After a build, the **Last build** column reports how each mapped row did:

| Cell | Meaning |
| --- | --- |
| `Set on all 12,483 items` | Every item in the feed had a value. |
| `Blank on 4,201 items` | You mapped it, and it produced nothing on that many items. |
| `17 items priced at zero or less` | `price` and `sale_price` only. The items stay in the feed. |
| `-` | Not mapped, or the feed has never built. |

Attributes left on "Don't include" are never counted.

## Excluded products

A blank on a *required* attribute means the item was left out of the feed entirely. The **Excluded products** panel below the mapping table lists up to fifty of the items the last build dropped, and why, each one a link to the element that needs fixing. The panel appears whenever the last build excluded anything, with **Download full list (CSV)** beside it for the rest.

The feeds index shows the same count in its **Issues** column.

## Items with no identifiers

When `brand`, `gtin`, and `mpn` are all blank on an item, a Google or Microsoft feed sends `identifier_exists: no`.

Meta, Pinterest, and TikTok have no such field. Meta and TikTok require a brand outright; a Pinterest item with no identifiers is sent without them.

## Images

Map one asset field to `image_link`. The first asset becomes the image, and up to ten more become `additional_image_link`. A field holding a single image and a field holding many behave the same way.

Microsoft has no `additional_image_link`, so a Microsoft feed sends the first image and nothing else.

Set **Use default value** on `image_link` to pick a fallback asset, used on items whose own image field is empty. It goes through the same image engine and transform as a mapped one.

`additional_image_link` can also be mapped to an asset field of its own, in which case its first ten assets are used instead of the overflow.

An asset with no public URL yields no image, and the item is excluded and counted.

Image and product URLs are percent-encoded before they are written.

### Image engine

**Image engine** decides what URL `image_link` carries:

- **None**: the asset's own URL, straight from your origin. This bypasses whatever image service the site uses.
- **Craft**: Craft generates a transform and the feed carries its URL.
- **Imager X** or **Small Pics**: the asset goes through that plugin's pipeline and the feed carries its CDN URL. Each appears only when the plugin is installed.

With the Craft engine, pick a named transform, or **Custom size** and set the width, height, and fit. Either dimension on its own is a valid transform.

**Test image** resolves `image_link` for the first item in the feed, using the mapping and image settings currently on screen, and fetches the result. You see the URL, a thumbnail, and whether it is a reachable image, before running a build. It appears once you pick an image engine, and is not offered while the engine is **None**.

It reports the image against the platform's minimum: 500 by 500 for Google, Meta, and TikTok, and 1000 by 1500 for Pinterest. Microsoft documents no minimum. The check compares width and height against those numbers and nothing else, so a landscape 2000 by 1500 image passes Pinterest's check while still being the wrong shape for it.

Map `image_link` first, then test.

## Prices

Prices come from Commerce, in the currency of the site's Commerce store, and are the price a logged-out shopper sees. Catalog pricing scoped to a customer group never reaches the feed.

On an entry feed there is no Commerce price to read, so you map one. Point `price` at a Number field. The plugin formats it into the store's currency, so a field holding `199` reaches the feed as `199.00 USD`. A field holding text such as "from 199" produces nothing, and the item is excluded.

`sale_price` appears only when a promotional price is lower than the price. Stores using the Sales system also get `sale_price_effective_date`; stores on Catalog Pricing Rules do not, because Commerce drops each rule's dates when it resolves a variant's price.

If items come back priced at zero, see [troubleshooting](./troubleshooting.md).
