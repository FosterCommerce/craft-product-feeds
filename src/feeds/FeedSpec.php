<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\helpers\FeedValue;
use Money\Money;

abstract class FeedSpec
{
	/**
	 * @var array<string, AttributeDefinition>|null
	 */
	private ?array $attributes = null;

	public static function forPlatform(Platform $platform): self
	{
		return match ($platform) {
			Platform::Google => new GoogleFeed(),
			Platform::Meta => new MetaFeed(),
			Platform::Microsoft => new MicrosoftFeed(),
			Platform::Pinterest => new PinterestFeed(),
			Platform::TikTok => new TikTokFeed(),
		};
	}

	/**
	 * Every attribute this platform accepts, in the order the document carries them.
	 *
	 * @return array<string, AttributeDefinition> keyed by handle
	 */
	final public function attributes(): array
	{
		if ($this->attributes === null) {
			$attributes = [];
			foreach ($this->defineAttributes() as $attributeDefinition) {
				$attributes[$attributeDefinition->name] = $attributeDefinition;
			}

			$this->attributes = $attributes;
		}

		return $this->attributes;
	}

	/**
	 * @return string[]
	 */
	final public function requiredAttributes(): array
	{
		return array_keys(array_filter(
			$this->attributes(),
			static fn (AttributeDefinition $definition): bool => $definition->required
		));
	}

	abstract public function fileExtension(): string;

	abstract public function mimeType(): string;

	abstract public function writer(string $filePath, string $channelTitle, string $channelLink): FeedWriterInterface;

	/**
	 * The platform's own page for an attribute, linked beside it on the mapping screen.
	 */
	abstract public function docUrl(string $attribute): ?string;

	/**
	 * Attributes `finalizeItem()` derives from the others. Excluded from the mapping screen.
	 *
	 * @return string[]
	 */
	public function derivedAttributes(): array
	{
		return [];
	}

	/**
	 * The attribute carrying the item's main image, where the platform has one. It takes the first image
	 * of its source, and the gallery takes the rest.
	 */
	public function imageAttribute(): ?string
	{
		return null;
	}

	/**
	 * The attribute carrying the item's extra images, where the platform has one. It defaults to the
	 * overflow from `imageAttribute()`, which a mapping to its own asset field replaces.
	 */
	public function galleryAttribute(): ?string
	{
		return null;
	}

	public function maxGalleryImages(): int
	{
		return 10;
	}

	/**
	 * Smallest image the platform accepts, as `[width, height]`, or null where it publishes no minimum.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public function minimumImageSize(): ?array
	{
		return null;
	}

	/**
	 * Translation key for the size advice shown under the image engine.
	 */
	public function imageSizeNote(): ?string
	{
		return null;
	}

	/**
	 * Translation key for a note shown under the mapping table.
	 */
	public function mappingNote(): ?string
	{
		return null;
	}

	public function formatMoney(Money $money): string
	{
		return FeedValue::money($money);
	}

	/**
	 * The element name the document emits an attribute under, where the platform names it differently
	 * (TikTok's `id` is `sku_id`). Only the written document uses this; everything else stays on the
	 * shared handle.
	 */
	public function documentName(string $attribute): string
	{
		return $attribute;
	}

	/**
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|list<string>>
	 */
	public function finalizeItem(array $item): array
	{
		return $item;
	}

	/**
	 * @return list<AttributeDefinition>
	 */
	abstract protected function defineAttributes(): array;
}
