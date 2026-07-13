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
	 * Derived from `brand`, `gtin` and `mpn` once they resolve, so no source can own it. The documented
	 * answer for a product with no identifiers, on the platforms that have it.
	 */
	protected const IDENTIFIER_EXISTS = 'identifier_exists';

	private const NAMESPACE_URI = 'http://base.google.com/ns/1.0';

	private const NAMESPACE_PREFIX = 'g';

	private const IDENTIFIER_ATTRIBUTES = ['brand', 'gtin', 'mpn'];

	final public function fileExtension(): string
	{
		return 'xml';
	}

	final public function mimeType(): string
	{
		return 'application/xml';
	}

	final public function writer(string $filePath, string $channelTitle, string $channelLink): FeedWriterInterface
	{
		return new RssFeedWriter(
			$filePath,
			$channelTitle,
			$channelLink,
			self::NAMESPACE_PREFIX,
			self::NAMESPACE_URI,
		);
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
	 * excludes.
	 *
	 * @param array<string, bool> $required attribute handle => required, for the ones that are
	 * @param array<string, int> $maxLengths attribute handle => the platform's own limit, where it
	 * differs from the shared default
	 * @param list<string> $exclude attributes this platform does not accept
	 * @return list<AttributeDefinition>
	 */
	protected function standardAttributes(array $required = [], array $maxLengths = [], array $exclude = []): array
	{
		$definitions = [];

		foreach (StandardAttribute::cases() as $case) {
			if (in_array($case->value, $exclude, true)) {
				continue;
			}

			$definitions[] = $case->definition(
				$required[$case->value] ?? false,
				$maxLengths[$case->value] ?? null,
			);
		}

		return $definitions;
	}

	/**
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|list<string>>
	 */
	protected function withIdentifierExists(array $item): array
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
	 * Every platform here except Google documents `in stock` rather than `in_stock`, and never the
	 * underscore form, so don't send it. Every underscore in the value is rewritten, which covers
	 * `out_of_stock` as well.
	 *
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|list<string>>
	 */
	protected function withSpacedAvailability(array $item): array
	{
		$availability = $item[StandardAttribute::Availability->value] ?? null;

		if (is_string($availability) && $availability !== '') {
			$item[StandardAttribute::Availability->value] = str_replace('_', ' ', $availability);
		}

		return $item;
	}

	protected function identifierExistsDefinition(): AttributeDefinition
	{
		return new AttributeDefinition(self::IDENTIFIER_EXISTS, AttributeKind::Text);
	}
}
