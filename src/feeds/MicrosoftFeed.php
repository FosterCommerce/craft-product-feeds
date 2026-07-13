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
	/**
	 * Microsoft documents the feed's fields on one page.
	 */
	private const DOC_URL = 'https://learn.microsoft.com/en-us/advertising/shopping-content/products-resource';

	/**
	 * Longer than Google's 5,000, so a description truncated for a Google feed need not be for this one.
	 */
	private const DESCRIPTION_MAX_LENGTH = 10_000;

	private const GOOGLE_PRODUCT_CATEGORY_MAX_LENGTH = 255;

	public function docUrl(string $attribute): ?string
	{
		return isset($this->attributes()[$attribute]) ? self::DOC_URL : null;
	}

	public function derivedAttributes(): array
	{
		return [self::IDENTIFIER_EXISTS];
	}

	/**
	 * Microsoft ignores the extra images: its docs say the field exists for Google compatibility and
	 * nothing else, so it is not offered for mapping.
	 */
	public function galleryAttribute(): ?string
	{
		return null;
	}

	/**
	 * Microsoft publishes a recommended size and no minimum, so `minimumImageSize()` stays null.
	 */
	public function imageSizeNote(): ?string
	{
		return 'feed.imageSizeMicrosoft';
	}

	public function finalizeItem(array $item): array
	{
		return $this->withIdentifierExists($this->withSpacedAvailability($item));
	}

	/**
	 * The same seven Google requires. The Content API marks `condition`, `brand`, `gtin` and `mpn` as
	 * required for an insert, but that is the API filling defaults and warning on missing identifiers:
	 * the feed file requires none of them, and Microsoft carries `identifier_exists` for the same
	 * reason Google does.
	 */
	protected function defineAttributes(): array
	{
		return [
			...$this->standardAttributes(
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
					'description' => self::DESCRIPTION_MAX_LENGTH,
					'google_product_category' => self::GOOGLE_PRODUCT_CATEGORY_MAX_LENGTH,
				],
				exclude: [StandardAttribute::AdditionalImageLink->value],
			),
			$this->identifierExistsDefinition(),
		];
	}
}
