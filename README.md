![Product Feeds](resources/img/header.png)

# Product Feeds

Build auto-updating product feeds for shopping and social platforms from Craft Commerce products and variants, Craft entries, or custom sources.

## Overview

- Publish feeds for Google, Meta, Microsoft, Pinterest, TikTok, and Klaviyo, each from a stable URL the platform fetches on a schedule.
- Build a feed from Commerce variants, from Commerce products for platforms and stores that list one item per product (Klaviyo), or from entries when you advertise a page rather than a product.
- Write a custom source in a module or plugin to publish items the built-in sources cannot, such as one item for each size an entry offers.
- Map each platform attribute to a product property, a Craft field, a default value, or a Twig template.
- Check a feed before the platform does: preview its items, see which attributes were blank or invalid on the last build, and test an image against the platform's minimum size.
- Keep each feed current, rebuilt on a schedule and whenever a product or entry in it changes.
- Split one catalog across several feeds (one per brand, one for items on promotion).

## How it works

You choose a filesystem for built feeds, then give each feed a platform and a source and map its attributes. The plugin builds feeds in Craft's queue and serves each one from its own URL. A scheduled `./craft product-feeds/feeds/build` and edits to products or entries trigger rebuilds. A stock change alone waits for the next scheduled build.

## Requirements

- Craft CMS `^5.9.0`
- Craft Commerce `^5.5.0`
- PHP `^8.2` with the `json`, `zlib`, and `xmlwriter` extensions

## Install

```sh
composer require fostercommerce/product-feeds
./craft plugin/install product-feeds
```

## Documentation

- [Installation](https://www.fostercommerce.com/craft-cms-plugins/product-feeds/docs/installation), the filesystem, settings, and scheduling the build
- [Mapping a feed](https://www.fostercommerce.com/craft-cms-plugins/product-feeds/docs/user-guide/mapping), connecting Craft data to platform attributes
- [Troubleshooting](https://www.fostercommerce.com/craft-cms-plugins/product-feeds/docs/user-guide/troubleshooting), why a feed failed or has fewer items than expected
- [Add a custom source](https://www.fostercommerce.com/craft-cms-plugins/product-feeds/docs/dev-guide/custom-sources), publishing items the built-in sources do not read

## License

Proprietary

---

<a href="https://www.fostercommerce.com" target="_blank"><img src="./resources/img/foster-commerce.svg" alt="Foster Commerce" width="160" height="40"></a>
