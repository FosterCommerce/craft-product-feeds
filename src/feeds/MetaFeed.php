<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

/**
 * Meta reuses Google's attribute names and accepts the same RSS document. It has no
 * `identifier_exists`, and requires what Google leaves conditional on it.
 */
class MetaFeed extends GoogleFormatFeed
{
	protected const DOC_URL = 'https://developers.facebook.com/docs/commerce-platform/catalog/fields/';

	/**
	 * Meta's minimum. It recommends 1024 by 1024.
	 */
	protected const MINIMUM_IMAGE_SIZE = [500, 500];

	protected const IMAGE_SIZE_NOTE = 'feed.imageSizeMeta';

	/**
	 * `brand` and `condition` are required here where Google treats them as conditional, because Meta
	 * has no `identifier_exists` to fall back on. `gtin` and `mpn` are only recommended.
	 *
	 * @see https://developers.facebook.com/docs/commerce-platform/catalog/fields/
	 */
	protected function defineAttributes(): array
	{
		return $this->standardAttributes(alsoRequired: ['brand', 'condition']);
	}
}
