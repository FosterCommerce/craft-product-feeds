# Installation

Requirements, install, plugin settings, and scheduling the build.

## Requirements

- Craft CMS `^5.2.0`
- Craft Commerce `^5.5.0`
- PHP `^8.2` with the `json`, `zlib`, and `xmlwriter` extensions

## Install

```sh
composer require fostercommerce/product-feeds
./craft plugin/install product-feeds
```

## Configure

**Settings -> Plugins -> Product Feeds.**

- **Filesystem**: where built feed files are stored. Required, no default. Craft serves the feed from its own URL, so this filesystem does not need public URLs. A local folder only works where the queue worker and the web server share a disk. Feeds are written to a `product-feeds/` directory inside it.
- **Batch size**: how many elements are loaded per pass while building. Default: `500`.
- **Build timeout**: seconds a build may run. Default: `3600`. It sets the queue job's TTR, and it is also how long a build may sit unfinished before the next `feeds/build` treats the feed as stalled and queues it again.
- **Build interval**: seconds a feed may go without rebuilding. Default: `3600`. The scheduled command rebuilds a feed once it is older than this.
- **Rebuild when products change**: rebuilds a feed as soon as a product in it is edited or deleted, without waiting for the next scheduled build. Editing several products in a row rebuilds the feed once. On by default. Stock levels are the exception, see below.

Any of these can be set per environment in `config/product-feeds.php`, keyed by the property name: `fsHandle`, `batchSize`, `buildTimeout`, `buildInterval`, and `rebuildOnChange`.

## Console commands

```sh
./craft product-feeds/feeds/build                 # queue every enabled feed that is due
./craft product-feeds/feeds/build --all           # queue every enabled feed, due or not
./craft product-feeds/feeds/build --feed=main     # queue every feed with that handle, enabled or not
./craft product-feeds/feeds/build --all --inline  # build in this process instead of queueing
```

`--feed` matches by handle whether or not the feed is enabled, so it builds a disabled feed too. A handle is unique within a site, so on a multi-site store it matches the feed of that handle on every site. Without it, only enabled feeds are queued.

`--inline` bypasses the queue and builds in the console process. A failed build writes its message to stderr and exits non-zero.

## Building on a schedule

Schedule `./craft product-feeds/feeds/build` on whatever your host provides. With no options it queues only the feeds whose last build is older than the build interval, so it can be run more often than the interval.

A crontab entry, run as often as you want feeds checked:

```sh
*/15 * * * * /path/to/craft product-feeds/feeds/build
```

Keep the schedule even with **Rebuild when products change** on. That setting reacts to a product being edited or deleted. Changing a stock level does not edit the product, so it triggers no rebuild; `availability` is recomputed by the next build.

## Queue

Craft runs pending queue jobs from control panel requests by default, so a large build can be picked up by a control panel page load and run there. Run a supervised worker instead, unless your host runs the queue for you:

```sh
./craft queue/listen
```

## Site URL

A feed's `link` attribute is the product or entry URL, and it must be absolute. Make sure the site's base URL is absolute in the environment the queue runs in, not only in the web environment.

## Checking the feed URL

After every successful build the plugin sends a `HEAD` request to the feed URL and shows the result under **Feed URL** on the feed's Settings tab. A failed build does not run the check.

This is advisory and never fails a build. A queue worker often cannot resolve the site's own public hostname, and basic auth on staging or a WAF in front of production will both fail the check.

If a fetch is going wrong, see [troubleshooting](./user-guide/troubleshooting.md).
