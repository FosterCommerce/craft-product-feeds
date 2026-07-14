<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use fostercommerce\productfeeds\enums\StandardAttribute;

/**
 * Microsoft Merchant Center, which powers Bing Shopping. It takes Google's attribute names and only
 * accepts an XML file that is already a Google-formatted one.
 *
 * @see https://learn.microsoft.com/en-us/advertising/shopping-content/products-resource
 */
class MicrosoftFeed extends GoogleFormatFeed
{
	protected const DOC_URL = 'https://learn.microsoft.com/en-us/advertising/shopping-content/products-resource';

	protected const HAS_IDENTIFIER_EXISTS = true;

	protected const IMAGE_SIZE_NOTE = 'feed.imageSizeMicrosoft';

	/**
	 * Longer than Google's 5,000, so a description truncated for a Google feed need not be for this one.
	 */
	private const DESCRIPTION_MAX_LENGTH = 10_000;

	private const GOOGLE_PRODUCT_CATEGORY_MAX_LENGTH = 255;

	/**
	 * Microsoft ignores `additional_image_link`, so it is not offered for mapping.
	 */
	public function galleryAttribute(): ?string
	{
		return null;
	}

	/**
	 * The same seven Google requires, and `identifier_exists` for the same reason.
	 */
	protected function defineAttributes(): array
	{
		return $this->standardAttributes(
			maxLengths: [
				'description' => self::DESCRIPTION_MAX_LENGTH,
				'google_product_category' => self::GOOGLE_PRODUCT_CATEGORY_MAX_LENGTH,
			],
			exclude: [StandardAttribute::AdditionalImageLink->value],
		);
	}
}
