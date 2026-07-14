<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

/**
 * Pinterest retail catalogs. Google's attribute names, and an RSS 2.0 document.
 *
 * @see https://help.pinterest.com/en/business/article/before-you-get-started-with-catalogs
 */
class PinterestFeed extends GoogleFormatFeed
{
	protected const DOC_URL = 'https://help.pinterest.com/en/business/article/before-you-get-started-with-catalogs';

	/**
	 * The 2:3 portrait image Pinterest recommends, and the floor the plugin warns below.
	 */
	protected const MINIMUM_IMAGE_SIZE = [1000, 1500];

	protected const IMAGE_SIZE_NOTE = 'feed.imageSizePinterest';

	private const TITLE_MAX_LENGTH = 500;

	private const DESCRIPTION_MAX_LENGTH = 10_000;

	private const PRODUCT_TYPE_MAX_LENGTH = 1000;

	/**
	 * Pinterest requires only the seven it needs to build a Pin. `brand` and `condition` are optional,
	 * and it has no `identifier_exists` to compensate with, so an item with no identifiers is sent
	 * without them.
	 */
	protected function defineAttributes(): array
	{
		return $this->standardAttributes(
			maxLengths: [
				'title' => self::TITLE_MAX_LENGTH,
				'description' => self::DESCRIPTION_MAX_LENGTH,
				'product_type' => self::PRODUCT_TYPE_MAX_LENGTH,
			],
		);
	}
}
