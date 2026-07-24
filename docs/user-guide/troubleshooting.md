# Troubleshooting

Why a feed failed, or came out thin.

## The build failed

The feeds index shows the failure and its reason in the feed's **Last built** column. The **Status** column reports whether the feed is enabled, not how its last build went.

These failures are permanent, and the queue will not retry them:

- **No filesystem is configured.** Choose one in the plugin's settings.
- **The configured filesystem no longer exists.** The filesystem the plugin points at was deleted or renamed. Choose another in the plugin's settings.
- **The site isn't assigned to a Commerce store.** A feed takes its currency from the store.
- **Required attributes aren't mapped.** The message names them.
- **The product types or entry types you selected have no public URL.** The message names them.
- **Nothing this feed can read has a public URL.** Every product type or entry type the feed could read lacks a landing page on this site.

Anything else is transient, and retried twice before the queue gives up.

## The feed built, but items are missing

The **Excluded products** panel on the feed's Mapping tab lists them, with a CSV download for the full set. An item is excluded when a required attribute came out blank for that item, most often:

- `link`: the product or entry has no URL, or the URL is relative. Check that the site's base URL is absolute in the environment the queue runs in.
- `image_link`: no asset, or the asset's filesystem has no public URLs.
- `description`: the field is empty on that item.

A Meta or TikTok feed also excludes items with no `brand` or `condition`, which the other three treat as optional.

## Pinterest rejects the images

Pinterest wants a portrait image, 1000 by 1500 at the smallest. A square transform that clears Google's 500 by 500 minimum is below Pinterest's.

Each feed carries its own image engine and size. Set the Pinterest feed to a 2:3 size, and use **Test image** to confirm before building.

## The feed URL returns the wrong thing

The feed is always served by Craft, at `https://your-site/product-feeds/<handle>-<token>.xml.gz`. It is never served from the storage filesystem's own URL.

Point the plugin at a filesystem with no public URLs. The token in the feed URL is the only credential on the feed route, and a public filesystem hands out the same files without it: the built feed, and the excluded-products CSV, which carries your SKUs, product titles, and control panel edit URLs. Use a filesystem dedicated to feeds.

The **Feed URL** notice on the feed's Settings tab reports whether the URL answered after the last build, and with what content type.

## The platform says the feed is empty or truncated

Feeds are served gzipped. If a proxy or WAF in front of Craft compresses the response again, the fetch fails to decompress. Serve `.xml.gz` without a `Content-Encoding` header, or use the `.xml` URL, which serves the same feed uncompressed.

Staging environments often sit behind server-level basic auth, which breaks a scheduled fetch with no sign of it in the feed.

## Every item is disapproved for missing shipping

The feed does not send per-item shipping or tax. Set them in your account on the platform.

## Some items are priced at zero

The mapping screen shows a count under `price` after each build. Those items go out with a price of `0.00`.

This is usually an unfilled field rather than a free product. On an entry feed, check that every entry has a value in the field mapped to `price`.

## The platform rejects the price

The feed quotes the price a logged-out shopper sees. If the landing page shows a different price, the item is disapproved for price mismatch. On a made-to-measure store, a starting price on the page and a starting price in the feed still count as a mismatch if the customer pays something else.

## The feed is stale

Feeds rebuild when the scheduled command finds them older than the build interval, and when someone edits a product they contain. If neither is happening, check that `./craft product-feeds/feeds/build` is scheduled on this host. See [installation](../installation.md).

Stock levels are the exception to auto-rebuild: changing one does not save the variant, so it triggers no rebuild on its own. Every build reads current stock, so the scheduled build, **Build now**, and a rebuild triggered by any other edit all refresh `availability`.

## The feed URL stopped working

Rotating a feed's token mints a new URL and moves the built feed to it, so the new URL serves the current feed straight away. The old URL stops working as soon as the rotation saves. Paste the new URL into the platform that fetches it.
