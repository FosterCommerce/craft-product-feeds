# Release Notes for Product Feeds

## 1.3.0 - 2026-09-25

### Added
- Added support for custom feed sources.
- Added the “Use Twig value” mapping option.
- Added the `backorder` availability value and the `availability_date` attribute to Google feeds.
- Added the ability to map `item_group_id`, `sale_price`, and `sale_price_effective_date` on entry feeds.
- Added the `color`, `size`, `material`, and `pattern` attributes to Google, Meta, Microsoft, Pinterest, and TikTok feeds.
- Added `fostercommerce\productfeeds\sources\CustomSource`.
- Added `fostercommerce\productfeeds\sources\SuppliedItem`.
- Added `ProductFeeds::EVENT_REGISTER_SOURCES`.

### Changed
- Product Feeds now requires Craft CMS 5.9 or later.
- The “Mapping” tab now lists every attribute the platform defines.
- The “Default value” box now appears only when the row's selected option uses it.
- The “Mapping” tab now lists fields only for the source, platform, and types the feed was last saved with.
- Changing a feed's source now resets its mapping to the new source's defaults.
- “Build now” and “Rotate feed URL” are now buttons beside “Save”.
- The “Excluded products” panel is now “Excluded items”.
- Items with the same `id` as an earlier item are now excluded from the feed.
- URL and image values must now use `http` or `https`.
- “Test image” now uses the feed's saved mapping for users who can't edit feeds.
- `productFeeds:edit` now lets a user write Twig values, which have full template access unless the site turns on `enableTwigSandbox`.

### Deprecated
- Deprecated `Feed::getSource()`. Use `FeedSource::forFeed()` instead.

## 1.2.0 - 2026-07-23

### Added
- Added support for mapping CKEditor fields to text attributes. ([#2](https://github.com/FosterCommerce/craft-product-feeds/issues/2))

### Changed
- Build diagnostics now record skipped items under `skippedByAttribute`. Note: Any previous counts will read as zero until the feed is rebuilt.

### Fixed
- Fixed a bug where an image attribute dropped a relative asset URL instead of resolving it against the site's base URL. ([#2](https://github.com/FosterCommerce/craft-product-feeds/issues/2))
- Fixed an issue where a dropped non-absolute URL or image value wasn't named on the mapping screen.
- Fixed a bug where a feed's URL returned a 404 after a build failed.
- Fixed a bug where a feed fetched while a build was publishing could be served truncated.
- Fixed a bug where changing a feed's platform left its previous file on the feed filesystem.
- Fixed a bug where a feed whose image plugin had been uninstalled excluded every item without naming the cause.
- Fixed a bug where the excluded products CSV could be published with rows missing.
- Fixed a bug where “Build now” reported a build as queued when one was already waiting.
- Fixed a bug where a feed created after a queue worker started wasn't rebuilt when its products changed.

## 1.1.0 - 2026-07-14

### Added
- Added a Klaviyo platform.
- Added an image test to the feed edit screen.

### Fixed
- Fixed issue where a feed's URL could 404.
- Fixed issue where a product title beginning with `=` could run as a formula when the excluded products CSV was opened in a spreadsheet.
- Fixed issue where multiple sequential edits could queue more than one build for the same feed.
- Fixed issue where downloading a large excluded products CSV could exhaust memory.
- Fixed issue where a backordered variant was listed as out of stock.

## 1.0.0 - 2026-07-11

### Added
- Added product feeds for Google Merchant Center, Meta Commerce Manager, Microsoft Merchant Center, Pinterest, and TikTok, built from Craft Commerce variants or Craft entries and served from a stable URL.
