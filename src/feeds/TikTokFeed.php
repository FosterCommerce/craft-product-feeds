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
	private const SKU_ID = 'sku_id';

	private const DOC_URL = 'https://ads.tiktok.com/help/article/catalog-product-parameters';

	/**
	 * TikTok's own minimum, and it wants a square image.
	 */
	private const MINIMUM_IMAGE_SIZE = [500, 500];

	public function docUrl(string $attribute): ?string
	{
		return isset($this->attributes()[$attribute]) ? self::DOC_URL : null;
	}

	public function minimumImageSize(): ?array
	{
		return self::MINIMUM_IMAGE_SIZE;
	}

	public function imageSizeNote(): ?string
	{
		return 'feed.imageSizeTikTok';
	}

	public function documentName(string $attribute): string
	{
		return $attribute === StandardAttribute::Id->value ? self::SKU_ID : $attribute;
	}

	public function finalizeItem(array $item): array
	{
		return $this->withSpacedAvailability($item);
	}

	/**
	 * `brand` and `condition` are required, as they are on Meta, and for the same reason: TikTok has no
	 * `identifier_exists` to fall back on.
	 */
	protected function defineAttributes(): array
	{
		return $this->standardAttributes(required: [
			'id' => true,
			'title' => true,
			'description' => true,
			'link' => true,
			'image_link' => true,
			'availability' => true,
			'price' => true,
			'brand' => true,
			'condition' => true,
		]);
	}
}
