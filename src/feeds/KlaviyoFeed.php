<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use craft\helpers\MoneyHelper;
use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\enums\StandardAttribute;
use Money\Money;

/**
 * A Klaviyo custom catalog feed, added in Klaviyo as a Catalog Source.
 *
 * Klaviyo takes a flat JSON document, one node deep, and names its own fields with a `$` prefix. It
 * has no availability string: stock reaches it as a number, and `$inventory_policy` says what to do
 * with an item once that number hits zero.
 *
 * @see https://developers.klaviyo.com/en/docs/guide_to_syncing_a_custom_catalog_feed_to_klaviyo
 */
class KlaviyoFeed extends FeedSpec
{
	protected const DOC_URL = 'https://developers.klaviyo.com/en/docs/guide_to_syncing_a_custom_catalog_feed_to_klaviyo';

	protected const IMAGE_SIZE_NOTE = 'feed.imageSizeKlaviyo';

	private const CATEGORIES = 'categories';

	private const INVENTORY_QUANTITY = 'inventory_quantity';

	private const INVENTORY_POLICY = 'inventory_policy';

	private const INVENTORY_POLICY_VALUES = ['0', '1', '2'];

	/**
	 * Klaviyo's own names for the fields it reads. Everything else it carries as custom metadata under
	 * the name the document gives it.
	 */
	private const DOCUMENT_NAMES = [
		'id' => '$id',
		'title' => '$title',
		'description' => '$description',
		'link' => '$link',
		'image_link' => '$image_link',
		'price' => '$price',
		self::INVENTORY_QUANTITY => '$inventory_quantity',
		self::INVENTORY_POLICY => '$inventory_policy',
	];

	public function fileExtension(): string
	{
		return 'json';
	}

	public function mimeType(): string
	{
		return 'application/json';
	}

	public function writer(string $filePath, string $channelTitle, string $channelLink): FeedWriter
	{
		return new JsonFeedWriter($filePath, $this->numericDocumentNames());
	}

	public function imageAttribute(): ?string
	{
		return StandardAttribute::ImageLink->value;
	}

	public function mappingNote(): ?string
	{
		return 'mapping.klaviyoNotice';
	}

	/**
	 * Klaviyo reads the price as a bare number and takes the currency from the account.
	 */
	public function formatMoney(Money $money): string
	{
		return (string) MoneyHelper::toDecimal($money);
	}

	public function documentName(string $attribute): string
	{
		return self::DOCUMENT_NAMES[$attribute] ?? $attribute;
	}

	protected function defineAttributes(): array
	{
		return [
			StandardAttribute::Id->definition(required: true),
			StandardAttribute::Title->definition(required: true),
			StandardAttribute::Description->definition(required: true),
			StandardAttribute::Link->definition(required: true),
			StandardAttribute::ImageLink->definition(required: true),
			// Not `StandardAttribute::Price->definition()`: its note is Google's rule about a zero price,
			// which Klaviyo does not have.
			new AttributeDefinition(StandardAttribute::Price->value, AttributeKind::Money),
			new AttributeDefinition(self::CATEGORIES, AttributeKind::CategoryList),
			new AttributeDefinition(self::INVENTORY_QUANTITY, AttributeKind::Number),
			new AttributeDefinition(
				self::INVENTORY_POLICY,
				AttributeKind::Number,
				values: self::INVENTORY_POLICY_VALUES,
				note: 'attribute.inventoryPolicyNote',
			),
		];
	}

	/**
	 * @return list<string>
	 */
	private function numericDocumentNames(): array
	{
		$numericDocumentNames = [];

		foreach ($this->attributes() as $name => $attributeDefinition) {
			if (in_array($attributeDefinition->attributeKind, [AttributeKind::Money, AttributeKind::Number], true)) {
				$numericDocumentNames[] = $this->documentName($name);
			}
		}

		return $numericDocumentNames;
	}
}
