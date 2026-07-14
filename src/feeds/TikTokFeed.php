<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use fostercommerce\productfeeds\enums\StandardAttribute;

/**
 * TikTok catalogs. Google's attribute names and the same RSS document, with one exception: TikTok
 * calls the identifier `sku_id`.
 *
 * @see https://ads.tiktok.com/help/article/catalog-product-parameters
 */
class TikTokFeed extends GoogleFormatFeed
{
	protected const DOC_URL = 'https://ads.tiktok.com/help/article/catalog-product-parameters';

	/**
	 * TikTok's minimum, square.
	 */
	protected const MINIMUM_IMAGE_SIZE = [500, 500];

	protected const IMAGE_SIZE_NOTE = 'feed.imageSizeTikTok';

	private const SKU_ID = 'sku_id';

	public function documentName(string $attribute): string
	{
		return $attribute === StandardAttribute::Id->value ? self::SKU_ID : $attribute;
	}

	/**
	 * `brand` and `condition` are required, as they are on Meta, and for the same reason: TikTok has no
	 * `identifier_exists` to fall back on.
	 */
	protected function defineAttributes(): array
	{
		return $this->standardAttributes(alsoRequired: ['brand', 'condition']);
	}
}
