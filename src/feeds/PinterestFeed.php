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
	private const DOC_URL = 'https://help.pinterest.com/en/business/article/before-you-get-started-with-catalogs';

	/**
	 * Pinterest is a portrait surface, so its minimum is a 2:3 image rather than a square one.
	 */
	private const MINIMUM_IMAGE_SIZE = [1000, 1500];

	private const TITLE_MAX_LENGTH = 500;

	private const DESCRIPTION_MAX_LENGTH = 10_000;

	private const PRODUCT_TYPE_MAX_LENGTH = 1000;

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
		return 'feed.imageSizePinterest';
	}

	public function finalizeItem(array $item): array
	{
		return $this->withSpacedAvailability($item);
	}

	/**
	 * Pinterest requires only the seven it needs to build a Pin. `brand` and `condition` are optional,
	 * and it has no `identifier_exists` to compensate with, so an item with no identifiers is sent
	 * without them.
	 */
	protected function defineAttributes(): array
	{
		return $this->standardAttributes(
			required: [
				'id' => true,
				'title' => true,
				'description' => true,
				'link' => true,
				'image_link' => true,
				'availability' => true,
				'price' => true,
			],
			maxLengths: [
				'title' => self::TITLE_MAX_LENGTH,
				'description' => self::DESCRIPTION_MAX_LENGTH,
				'product_type' => self::PRODUCT_TYPE_MAX_LENGTH,
			],
		);
	}
}
