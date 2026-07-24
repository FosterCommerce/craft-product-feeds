# Changelog

## [Unreleased]

### Fixed
- Fixed issue where a feed URL returned a 404 after a build failed.
- Fixed issue where a feed fetched while a build was publishing could be served truncated.
- Fixed issue where changing a feed's platform left its previous file on the feed filesystem.
- Fixed issue where a feed whose image plugin had been uninstalled excluded every item without naming the cause.
- Fixed issue where the excluded products CSV could be published with rows missing.
- Fixed issue where Build now reported a build as queued when one was already waiting.
- Fixed issue where a feed created after a queue worker started was not rebuilt when its products changed.

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
