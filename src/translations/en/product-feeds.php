<?php

declare(strict_types=1);

return [
	// Navigation
	'nav.productFeeds' => 'Product Feeds',

	// Permissions
	'permission.viewFeeds' => 'View product feeds',
	'permission.editFeeds' => 'Create and edit product feeds',
	'permission.buildFeeds' => 'Build and preview product feeds',

	// Platforms and sources
	'platform.google' => 'Google Merchant Center',
	'platform.meta' => 'Meta Commerce Manager',
	'platform.microsoft' => 'Microsoft Merchant Center',
	'platform.pinterest' => 'Pinterest catalogs',
	'platform.tiktok' => 'TikTok catalogs',
	'source.variants' => 'Commerce variants',
	'source.entries' => 'Entries',

	// Build status
	'buildStatus.pending' => 'Never built',
	'buildStatus.building' => 'Building',
	'buildStatus.ok' => 'Built',
	'buildStatus.failed' => 'Failed',

	// Feeds index
	'index.noFilesystem' => 'Choose a filesystem before creating a feed.',
	'index.openSettings' => 'Open settings',
	'index.noFeeds' => 'No feeds yet.',
	'index.openFeed' => 'Open',
	'index.reordered' => 'Feeds reordered.',
	'index.reorderFailed' => 'Couldn’t reorder feeds.',
	'index.nameColumn' => 'Name',
	'index.platformColumn' => 'Platform',
	'index.sourceColumn' => 'Source',
	'index.statusColumn' => 'Status',
	'index.lastBuiltColumn' => 'Last built',
	'index.itemsColumn' => 'Items',
	'index.issuesColumn' => 'Issues',
	'index.excludedCount' => '{n} excluded',
	'index.sizeColumn' => 'Size',
	'index.urlColumn' => 'URL',
	'index.buildButton' => 'Build',

	// Feed edit screen
	'edit.settingsTab' => 'Settings',
	'edit.mappingTab' => 'Mapping',

	// Feeds
	'feed.new' => 'New feed',
	'feed.saved' => 'Feed saved.',
	'feed.saveFailed' => 'Couldn’t save feed.',
	'feed.deleted' => 'Feed deleted.',
	'feed.buildQueued' => 'Build queued.',
	'feed.buildNow' => 'Build now',
	'feed.duplicate' => 'Duplicate',
	'feed.rotateToken' => 'Rotate feed URL',
	'feed.rotateTokenConfirm' => 'Generate a new feed URL? The current URL stops working immediately.',
	'feed.tokenRotated' => 'A new feed URL was generated. The old URL no longer works.',
	'feed.tokenRotateFailed' => 'Couldn’t generate a new feed URL. The current URL still works.',
	'feed.copyOf' => 'Copy of {name}',
	'feed.nameLabel' => 'Name',
	'feed.handleLabel' => 'Handle',
	'feed.platformLabel' => 'Platform',
	'feed.sourceLabel' => 'Source',
	'feed.sourceInstructions' => 'Commerce variants for a normal catalog. Entries for configurator and made-to-measure stores, where what you advertise is a page.',
	'feed.filterLabel' => 'Filter',
	'feed.filterInstructions' => 'Only products matching every rule are included. Leave empty to include them all.',
	'filter.addRule' => 'Add a filter',
	'feed.enabledLabel' => 'Enabled',
	'feed.urlLabel' => 'Feed URL',
	'feed.urlInstructions' => 'Paste this into Merchant Center or Commerce Manager.',
	'feed.urlReachable' => 'The feed URL answered, serving {contentType}.',
	'feed.urlUnreachable' => 'The feed URL did not answer: {detail}. Basic auth on staging, a WAF, or a firewall between the queue worker and the site will each cause this.',
	'feed.entryTypesLabel' => 'Entry types',
	'feed.productTypesLabel' => 'Product types',
	'feed.sourceIdsInstructions' => 'Leave all unchecked to include every one. Only those with a public URL on this site are listed.',
	'feed.imageTransformInstructions' => 'How the feed’s image URLs are produced. Imager X and Small Pics appear when installed, and publish their own CDN URL.',
	'feed.imageEngineLabel' => 'Image engine',
	'feed.craftTransformLabel' => 'Craft transform',
	'feed.imageWidthLabel' => 'Width',
	'feed.imageHeightLabel' => 'Height',
	'feed.imageFitLabel' => 'Fit',
	'feed.imageSizeGoogle' => 'Use 800 by 800 px or larger for Google, and never below the 500 by 500 minimum.',
	'feed.imageSizeMeta' => 'Use 1024 by 1024 px for Meta, and never below the 500 by 500 minimum.',
	'feed.imageSizeMicrosoft' => 'Microsoft recommends 200 by 200 px and publishes no minimum. Images up to 3.9 MB are accepted.',
	'feed.imageSizePinterest' => 'Pinterest needs a portrait image, 1000 by 1500 px at the smallest. A square image is below its minimum.',
	'feed.imageSizeTikTok' => 'TikTok wants a square image, 500 by 500 px at the smallest. JPG and PNG only.',

	// Image transforms
	'imageTransform.customSize' => 'Custom size',
	'imageEngine.none' => 'None (the asset’s own URL)',
	'imageEngine.craft' => 'Craft',
	'imageEngine.imagerx' => 'Imager X',
	'imageEngine.smallpics' => 'Small Pics',
	'imageFit.crop' => 'Crop to size',
	'imageFit.fit' => 'Fit within size',
	'imageTest.button' => 'Test image',
	'imageTest.noProduct' => 'No product was found for this source.',
	'imageTest.noUrl' => 'The image mapping produced no URL for the first product. Check the mapping, and that the engine’s plugin is installed and configured.',
	'imageTest.meetsMinimum' => 'Meets the {width}x{height} minimum.',
	'imageTest.belowMinimum' => 'Below the {width}x{height} minimum.',

	// Mapping
	'mapping.dontInclude' => 'Don’t include',
	'mapping.useDefaultValue' => 'Use default value',
	'mapping.chooseDefaultImage' => 'Choose an image',
	'mapping.imageOverflow' => 'Extra images from {attribute}',
	'mapping.variantProperties' => 'Variant properties',
	'mapping.productProperties' => 'Product properties',
	'mapping.entryProperties' => 'Entry properties',
	'mapping.variantFields' => 'Variant fields',
	'mapping.entryFields' => 'Entry fields',
	'mapping.productFields' => 'Product fields',
	'mapping.attributeColumn' => 'Attribute',
	'mapping.sourceColumn' => 'Source',
	'mapping.defaultColumn' => 'Default value',
	'mapping.dataIssuesColumn' => 'Last build',
	'mapping.blankItems' => 'Blank on {n} items',
	'mapping.setOnAllItems' => 'Set on all {n} items',
	'mapping.nonPositivePrice' => '{n} items priced at zero or less',
	'mapping.docLink' => '{platform} documentation',
	'mapping.identifierExistsNotice' => 'When brand, gtin and mpn are all blank on an item, the feed sends identifier_exists=no.',
	'mapping.excludedHeading' => 'Excluded products',
	'mapping.excludedIntro' => 'The last build left out {count} items because a required attribute was blank. Open one to fix it, then rebuild.',
	'mapping.excludedReason' => 'missing {attribute}',
	'mapping.excludedTruncated' => 'Showing the first {shown} of {total}. Download the full list below.',
	'mapping.excludedDownload' => 'Download full list (CSV)',

	// Attribute notes
	'attribute.priceNote' => 'A price of zero or less is rejected, except for contract phones and subscription hardware. The item is sent either way.',

	// Preview
	'preview.heading' => 'Preview',
	'preview.instructions' => 'The first few products this feed would publish, using its saved mapping. Save your changes before previewing them.',
	'preview.button' => 'Preview items',
	'preview.saveFirst' => 'Save the feed to preview it.',
	'preview.wouldSkip' => 'Excluded from the {platform} feed: {attribute} is required and blank.',
	'preview.allRequired' => 'All required attributes are set.',
	'preview.empty' => 'Nothing to show. Check the source and its filter.',

	// Plugin settings
	'settings.filesystem' => 'Filesystem',
	'settings.filesystemInstructions' => 'Where built feed files are stored.',
	'settings.batchSize' => 'Batch size',
	'settings.batchSizeInstructions' => 'How many elements are loaded per pass while building.',
	'settings.buildTimeout' => 'Build timeout',
	'settings.buildTimeoutInstructions' => 'Seconds a build may run. Craft Cloud caps queue jobs at 15 minutes regardless of this setting.',
	'settings.buildInterval' => 'Build interval',
	'settings.buildIntervalInstructions' => 'Seconds a feed may go without rebuilding. The scheduled command rebuilds a feed once it is older than this, so it is safe to run the command more often.',
	'settings.rebuildOnChange' => 'Rebuild when products change',
	'settings.rebuildOnChangeInstructions' => 'Rebuild a feed as soon as a product in it is edited or deleted, so a price change reaches the platform without waiting for the next scheduled build. Editing several products in a row rebuilds the feed once. Stock levels are the exception: changing one does not edit the product, so a stock change on its own triggers no rebuild. Every build reads current stock.',

	// Jobs
	'job.buildFeed' => 'Building product feed',

	// Footer
	'footer.supportBy' => 'Support by',
	'footer.supportTitle' => 'Foster Commerce Support',

	// Errors
	'error.attributeAlreadyTaken' => '“{value}” is already in use.',
	'error.noFilesystem' => 'No filesystem is configured for product feeds. Choose one in the plugin’s settings.',
	'error.missingFilesystem' => 'The “{handle}” filesystem no longer exists.',
	'error.builtFileUnreadable' => 'The built feed file could not be read back. Check the temp directory’s free space and permissions.',
	'error.publishFailed' => 'The built feed could not be moved into place at “{path}” on the feed filesystem.',
	'error.noStoreForSite' => 'This feed’s site isn’t assigned to a Commerce store, so it has no currency.',
	'error.noSourcesWithUrls' => 'Nothing this feed can read has a public URL on this site. A feed item needs a landing page.',
	'error.sourcesWithoutUrls' => 'These have no public URL on this site, so their items would have no landing page: {names}',
	'error.requiredAttributesUnmapped' => 'These required attributes aren’t mapped: {attributes}',
	'error.defaultNotAllowed' => 'The default for {attribute} must be one of: {allowed}. Got “{value}”.',
	'error.defaultNotNumeric' => 'The default for {attribute} must be a number, with a period as the decimal separator. Got “{value}”.',
	'error.defaultNotAbsoluteUrl' => 'The default for {attribute} must be an absolute URL. Got “{value}”.',
	'error.defaultNotAnImage' => 'The default image no longer exists, or is not an image. Choose another.',
	'error.mappingSourceNotAllowed' => '“{field}” can’t be used for {attribute}. Choose a field of a type that attribute accepts.',
];
