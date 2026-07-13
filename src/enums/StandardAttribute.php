<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

use fostercommerce\productfeeds\feeds\AttributeDefinition;

/**
 * The attributes the shopping platforms agree on. A spec composes its table from these; requiredness
 * is the spec's to declare.
 *
 * Declaration order is the order the document carries them, and `ImageLink` must stay ahead of
 * `AdditionalImageLink`: the builder fills the gallery from the images the main image left over, so
 * reversing the two empties every gallery.
 */
enum StandardAttribute: string
{
	case Id = 'id';

	case ItemGroupId = 'item_group_id';

	case Title = 'title';

	case Description = 'description';

	case Link = 'link';

	case ImageLink = 'image_link';

	case AdditionalImageLink = 'additional_image_link';

	case Availability = 'availability';

	case Price = 'price';

	case SalePrice = 'sale_price';

	case SalePriceEffectiveDate = 'sale_price_effective_date';

	case Brand = 'brand';

	case Gtin = 'gtin';

	case Mpn = 'mpn';

	case Condition = 'condition';

	case ProductType = 'product_type';

	case GoogleProductCategory = 'google_product_category';

	case CustomLabel0 = 'custom_label_0';

	case CustomLabel1 = 'custom_label_1';

	case CustomLabel2 = 'custom_label_2';

	case CustomLabel3 = 'custom_label_3';

	case CustomLabel4 = 'custom_label_4';

	/**
	 * Identical on every platform that has the concept.
	 */
	public const CONDITION_VALUES = ['new', 'refurbished', 'used'];

	/**
	 * @param int|null $maxLength the platform's own limit, where it is not the one Google publishes
	 */
	public function definition(bool $required = false, ?int $maxLength = null): AttributeDefinition
	{
		return new AttributeDefinition(
			$this->value,
			$this->kind(),
			$required,
			$this->values(),
			$this->note(),
			$maxLength ?? $this->maxLength(),
		);
	}

	private function kind(): AttributeKind
	{
		return match ($this) {
			self::Description => AttributeKind::LongText,
			self::Link => AttributeKind::Url,
			self::ImageLink, self::AdditionalImageLink => AttributeKind::Image,
			self::Price, self::SalePrice => AttributeKind::Money,
			self::ProductType => AttributeKind::CategoryPath,
			default => AttributeKind::Text,
		};
	}

	/**
	 * @return list<string>
	 */
	private function values(): array
	{
		return match ($this) {
			self::Availability => Availability::values(),
			self::Condition => self::CONDITION_VALUES,
			default => [],
		};
	}

	private function note(): ?string
	{
		return match ($this) {
			self::Price, self::SalePrice => 'attribute.priceNote',
			default => null,
		};
	}

	/**
	 * Where truncating still leaves a usable value. `id` and `gtin` have limits too, but a shortened
	 * identifier is a different product, so they are never truncated.
	 */
	private function maxLength(): ?int
	{
		return match ($this) {
			self::Title => 150,
			self::Description => 5000,
			self::Brand, self::Mpn => 70,
			self::ProductType => 750,
			self::CustomLabel0, self::CustomLabel1, self::CustomLabel2, self::CustomLabel3, self::CustomLabel4 => 100,
			default => null,
		};
	}
}
