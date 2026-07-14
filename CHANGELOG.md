# Changelog

## 1.1.0 - 2026-07-14

### Added
- Added a Klaviyo platform
- Image test on the feed edit screen

### Fixed
- Fixed issue where a feed's URL could 404.
- Fixed issue where a product title beginning with `=` could run as a formula when the excluded products CSV was opened in a spreadsheet
- Fixed issue where multiple sequential edits could queue more than one build for the same feed
- Fixed issue where downloading a large excluded products CSV could exhaust memory
- Backordered variants now report in stock instead of out of stock

## 1.0.0 - 2026-07-11

### Added
- Added product feeds for Google Merchant Center, Meta Commerce Manager, Microsoft Merchant Center, Pinterest, and TikTok, built from Craft Commerce variants or Craft entries and served from a stable URL.
