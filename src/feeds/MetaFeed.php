<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

/**
 * Meta reuses Google's attribute names and accepts the same RSS document. It has no
 * `identifier_exists`, and requires what Google leaves conditional on it.
 */
class MetaFeed extends GoogleFormatFeed
{
	/**
	 * Meta documents every catalog field on one page.
	 */
	private const DOC_URL = 'https://developers.facebook.com/docs/commerce-platform/catalog/fields/';

	/**
	 * Meta's own minimum. It recommends 1024 by 1024.
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
		return 'feed.imageSizeMeta';
	}

	public function finalizeItem(array $item): array
	{
		return $this->withSpacedAvailability($item);
	}

	/**
	 * `brand` and `condition` are required here where Google treats them as conditional, because Meta
	 * has no `identifier_exists` to fall back on. `gtin` and `mpn` are only recommended.
	 *
	 * @see https://developers.facebook.com/docs/commerce-platform/catalog/fields/
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
