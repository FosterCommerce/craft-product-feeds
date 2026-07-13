<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

class GoogleFeed extends GoogleFormatFeed
{
	/**
	 * Google's own minimum. An item below it is rejected.
	 */
	private const MINIMUM_IMAGE_SIZE = [500, 500];

	/**
	 * Google's help page per attribute, by answer ID. The five custom labels share one page.
	 *
	 * @see https://support.google.com/merchants/answer/7052112
	 */
	private const DOC_URLS = [
		'id' => 6324405,
		'title' => 6324415,
		'description' => 6324468,
		'link' => 6324416,
		'image_link' => 12472547,
		'additional_image_link' => 12472826,
		'availability' => 12472827,
		'price' => 12471842,
		'sale_price' => 12471623,
		'sale_price_effective_date' => 12471843,
		'brand' => 12468352,
		'gtin' => 12473440,
		'mpn' => 12474954,
		'identifier_exists' => 12472746,
		'condition' => 12471921,
		'item_group_id' => 12472646,
		'product_type' => 6324406,
		'google_product_category' => 6324436,
		'custom_label_0' => 6324473,
		'custom_label_1' => 6324473,
		'custom_label_2' => 6324473,
		'custom_label_3' => 6324473,
		'custom_label_4' => 6324473,
	];

	public function docUrl(string $attribute): ?string
	{
		$answer = self::DOC_URLS[$attribute] ?? null;

		return $answer === null
			? null
			: sprintf('https://support.google.com/merchants/answer/%d', $answer);
	}

	public function derivedAttributes(): array
	{
		return [self::IDENTIFIER_EXISTS];
	}

	public function minimumImageSize(): ?array
	{
		return self::MINIMUM_IMAGE_SIZE;
	}

	public function imageSizeNote(): ?string
	{
		return 'feed.imageSizeGoogle';
	}

	public function mappingNote(): ?string
	{
		return 'mapping.identifierExistsNotice';
	}

	public function finalizeItem(array $item): array
	{
		return $this->withIdentifierExists($item);
	}

	/**
	 * `brand`, `gtin` and `mpn` are not required: Google's spec says to omit them and send
	 * `identifier_exists: no` when a product has none.
	 *
	 * @see https://support.google.com/merchants/answer/6324478
	 */
	protected function defineAttributes(): array
	{
		return [
			...$this->standardAttributes(required: [
				'id' => true,
				'title' => true,
				'description' => true,
				'link' => true,
				'image_link' => true,
				'availability' => true,
				'price' => true,
			]),
			$this->identifierExistsDefinition(),
		];
	}
}
