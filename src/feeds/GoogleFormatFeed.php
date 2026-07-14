<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\enums\StandardAttribute;

/**
 * The platforms that take Google's RSS document and attribute names.
 *
 * A subclass declares where it diverges: which attributes are required, which it drops or renames,
 * its own length limits, how availability is worded, and how large an image has to be.
 */
abstract class GoogleFormatFeed extends FeedSpec
{
	/**
	 * Google and Microsoft accept `identifier_exists: no` from a product with no brand, gtin or mpn. The
	 * rest have no such field, so an item without identifiers is sent without them.
	 */
	protected const HAS_IDENTIFIER_EXISTS = false;

	/**
	 * Meta, Microsoft, Pinterest and TikTok document `in stock`, where Google documents `in_stock`.
	 */
	protected const SPACED_AVAILABILITY = true;

	private const IDENTIFIER_EXISTS = 'identifier_exists';

	private const NAMESPACE_URI = 'http://base.google.com/ns/1.0';

	private const NAMESPACE_PREFIX = 'g';

	private const IDENTIFIER_ATTRIBUTES = ['brand', 'gtin', 'mpn'];

	/**
	 * Required on every platform in this format: without them an item has no identity, no landing page,
	 * no price or no stock state, and the platform rejects it.
	 */
	private const CORE_REQUIRED = [
		'id',
		'title',
		'description',
		'link',
		'image_link',
		'availability',
		'price',
	];

	final public function fileExtension(): string
	{
		return 'xml';
	}

	final public function mimeType(): string
	{
		return 'application/xml';
	}

	final public function writer(string $filePath, string $channelTitle, string $channelLink): FeedWriter
	{
		return new RssFeedWriter(
			$filePath,
			$channelTitle,
			$channelLink,
			self::NAMESPACE_PREFIX,
			self::NAMESPACE_URI,
		);
	}

	public function derivedAttributes(): array
	{
		return static::HAS_IDENTIFIER_EXISTS ? [self::IDENTIFIER_EXISTS] : [];
	}

	public function mappingNote(): ?string
	{
		return static::HAS_IDENTIFIER_EXISTS ? 'mapping.identifierExistsNotice' : null;
	}

	/**
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|list<string>>
	 */
	final public function finalizeItem(array $item): array
	{
		if (static::SPACED_AVAILABILITY) {
			$item = $this->withSpacedAvailability($item);
		}

		return static::HAS_IDENTIFIER_EXISTS ? $this->withIdentifierExists($item) : $item;
	}

	public function imageAttribute(): ?string
	{
		return StandardAttribute::ImageLink->value;
	}

	public function galleryAttribute(): ?string
	{
		return StandardAttribute::AdditionalImageLink->value;
	}

	/**
	 * The shared attribute table, in the order the document carries them, less the ones this platform
	 * excludes, plus `identifier_exists` where the platform has it.
	 *
	 * @param list<string> $alsoRequired what this platform requires on top of `CORE_REQUIRED`
	 * @param array<string, int> $maxLengths attribute handle => the platform's own limit, where it
	 * differs from the shared default
	 * @param list<string> $exclude attributes this platform does not accept
	 * @return list<AttributeDefinition>
	 */
	protected function standardAttributes(array $alsoRequired = [], array $maxLengths = [], array $exclude = []): array
	{
		$required = array_fill_keys([...self::CORE_REQUIRED, ...$alsoRequired], true);
		$definitions = [];

		foreach (StandardAttribute::cases() as $standardAttribute) {
			if (in_array($standardAttribute->value, $exclude, true)) {
				continue;
			}

			$definitions[] = $standardAttribute->definition(
				$required[$standardAttribute->value] ?? false,
				$maxLengths[$standardAttribute->value] ?? null,
			);
		}

		if (static::HAS_IDENTIFIER_EXISTS) {
			$definitions[] = new AttributeDefinition(self::IDENTIFIER_EXISTS, AttributeKind::Text);
		}

		return $definitions;
	}

	/**
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|list<string>>
	 */
	private function withIdentifierExists(array $item): array
	{
		foreach (self::IDENTIFIER_ATTRIBUTES as $attribute) {
			if (($item[$attribute] ?? '') !== '') {
				return $item;
			}
		}

		$item[self::IDENTIFIER_EXISTS] = 'no';

		return $item;
	}

	/**
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|list<string>>
	 */
	private function withSpacedAvailability(array $item): array
	{
		$availability = $item[StandardAttribute::Availability->value] ?? null;

		if (is_string($availability) && $availability !== '') {
			$item[StandardAttribute::Availability->value] = str_replace('_', ' ', $availability);
		}

		return $item;
	}
}
