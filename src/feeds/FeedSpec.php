<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\enums\StandardAttribute;
use fostercommerce\productfeeds\helpers\FeedValue;
use Money\Money;

abstract class FeedSpec
{
	/**
	 * The most extra images an item may carry. The same on every platform here.
	 */
	public const MAX_GALLERY_IMAGES = 10;

	/**
	 * The one page the platform documents its fields on. Google is the exception: it has a page per
	 * attribute, so it overrides `docUrl()` instead.
	 */
	protected const DOC_URL = null;

	/**
	 * Smallest image the platform accepts, as `[width, height]`, or null where it publishes no minimum.
	 *
	 * @var array{0: int, 1: int}|null
	 */
	protected const MINIMUM_IMAGE_SIZE = null;

	/**
	 * Translation key for the size advice shown under the image engine.
	 */
	protected const IMAGE_SIZE_NOTE = null;

	/**
	 * @var array<string, AttributeDefinition>|null
	 */
	private ?array $attributes = null;

	public static function forPlatform(Platform $platform): self
	{
		return match ($platform) {
			Platform::Google => new GoogleFeed(),
			Platform::Klaviyo => new KlaviyoFeed(),
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

	/**
	 * Whether the platform carries a promotion in its own `sale_price` field. When it does not, a source
	 * must send the promotional price as `price`, not the list price.
	 */
	final public function separatesSalePrice(): bool
	{
		return isset($this->attributes()[StandardAttribute::SalePrice->value]);
	}

	abstract public function fileExtension(): string;

	abstract public function mimeType(): string;

	/**
	 * `$channelTitle` and `$channelLink` are RSS framing; a JSON spec ignores them.
	 */
	abstract public function writer(string $filePath, string $channelTitle, string $channelLink): FeedWriter;

	/**
	 * The platform's own page for an attribute, linked beside it on the mapping screen.
	 */
	public function docUrl(string $attribute): ?string
	{
		return isset($this->attributes()[$attribute]) ? static::DOC_URL : null;
	}

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
	 * The attribute carrying the item's main image, where the platform has one.
	 */
	public function imageAttribute(): ?string
	{
		return null;
	}

	/**
	 * The attribute carrying the item's extra images, where the platform has one.
	 */
	public function galleryAttribute(): ?string
	{
		return null;
	}

	/**
	 * @return array{0: int, 1: int}|null
	 */
	public function minimumImageSize(): ?array
	{
		return static::MINIMUM_IMAGE_SIZE;
	}

	public function imageSizeNote(): ?string
	{
		return static::IMAGE_SIZE_NOTE;
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
