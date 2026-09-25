![Product Feeds](resources/img/header.png)

# Product Feeds

Build auto-updating **product feeds** for shopping and social platforms from Craft Commerce variants, Craft entries, or custom sources.

## Overview

- Publish feeds for Google, Meta, Microsoft, Pinterest, TikTok, and Klaviyo, each from a stable URL the platform fetches on a schedule.
- Build a feed from Commerce variants, with SKU, price, and stock taken from Commerce, or from entries when you advertise a page rather than a product.
- Write a custom source in a module or plugin to publish items the built-in sources cannot, such as one item for each size an entry offers.
- Map each platform attribute to a product property, a Craft field, a default value, or a Twig template.
- Check a feed before the platform does: preview its items, see which attributes were blank or invalid on the last build, and test an image against the platform's minimum size.
- Keep each feed current, rebuilt on a schedule and whenever a product or entry in it changes.
- Split one catalog across several feeds (one per brand, one for items on promotion).

## Use Product Feeds when

- Your products have to appear on Google, Meta, Microsoft, Pinterest, TikTok, or Klaviyo, and each feed has to stay current as the catalog changes.
- What you advertise is an entry rather than a Commerce product, such as a configurator or made-to-measure page.
- Your catalog does not match one item per product, such as one entry sold in several sizes, and a module or plugin can supply the items.
- A few attribute values need a different shape than your fields hold, such as a combined title or a computed date.
- The people who maintain the feed need to see blank or invalid values before the platform reports them.

## Requirements

- Craft CMS `^5.9.0`
- Craft Commerce `^5.5.0`
- PHP `^8.2` with the `json`, `zlib`, and `xmlwriter` extensions

## Install

```sh
composer require fostercommerce/product-feeds
./craft plugin/install product-feeds
```

Then choose a filesystem under **Settings -> Plugins -> Product Feeds**, and schedule the build command.

For the full guide, see [installation](./docs/installation.md).

## Platforms

Each feed targets one platform: Google, Meta, Microsoft, Pinterest, TikTok, or Klaviyo. The platform sets which attributes the feed has, and the maximum length of each text value.

Each feed also has its own image engine and size, so a Pinterest feed can send portrait images while a Google feed sends square ones from the same Assets field. The image engine can be the asset's own URL, a Craft transform, Imager X, or Small Pics.

For what each platform sends, see [attributes](./docs/reference/attributes.md).

## Sources

A feed's data can come from Commerce variants, with SKU, price, and stock from Commerce, or from entries when you advertise a page rather than a product. To limit a feed to part of the catalog, add rules to its **Filter**.

For anything else, a developer can write a [custom source](./docs/dev-guide/custom-sources.md).

## Mapping

Each attribute can get mapped to a native value, a Craft field, a default value, or a Twig template. After each build, every attribute reports how many items were blank or invalid, and **Excluded items** lists what the build left out and why.

For the full guide, see [mapping a feed](./docs/user-guide/mapping.md).

## Documentation

For the full documentation, see the [Product Feeds documentation](https://www.fostercommerce.com/craft-cms-plugins/product-feeds/docs).

## License

Proprietary

---

<a href="https://www.fostercommerce.com" target="_blank"><img src="./resources/img/foster-commerce.svg" alt="Foster Commerce" width="160" height="40"></a>
