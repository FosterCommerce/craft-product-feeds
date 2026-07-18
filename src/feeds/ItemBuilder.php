<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\helpers\FeedValue;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\BuildDiagnostics;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\sources\FeedSource;
use Money\Currency;
use Money\Money;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Builds one feed item from one element.
 */
final readonly class ItemBuilder
{
	/**
	 * Every attribute's mapping, parsed once per build rather than once per item.
	 *
	 * @var array<string, array{kind: string, value: string, default: string}>
	 */
	private array $mappings;

	public function __construct(
		private Feed $feed,
		private FeedSpec $feedSpec,
		private FeedSource $feedSource,
		private BuildDiagnostics $buildDiagnostics,
	) {
		$mappings = [];

		foreach (array_keys($feedSpec->attributes()) as $name) {
			$parsed = Mapping::parse($feed->mappingSource($name, $feedSpec));

			$mappings[$name] = [
				'kind' => $parsed['kind'],
				'value' => $parsed['value'],
				'default' => $feed->mappingDefault($name),
			];
		}

		$this->mappings = $mappings;
	}

	/**
	 * The item's values, keyed by the shared attribute handle rather than the platform's own name.
	 *
	 * @return array<string, string|list<string>>
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function forElement(ElementInterface $element): array
	{
		$computedAttributes = $this->feedSource->computedAttributes();
		$derivedAttributes = $this->feedSpec->derivedAttributes();
		$imageAttribute = $this->feedSpec->imageAttribute();
		$galleryAttribute = $this->feedSpec->galleryAttribute();

		$item = [];
		// The gallery takes the images the main image left over, so a spec has to declare its image
		// attribute before its gallery one or every gallery comes out empty.
		$imageValues = [];

		foreach ($this->feedSpec->attributes() as $attributeName => $attributeDefinition) {
			if (in_array($attributeName, $derivedAttributes, true)) {
				continue;
			}

			$isComputed = in_array($attributeName, $computedAttributes, true);
			$values = $this->valuesFor($element, $attributeDefinition, $isComputed);

			if ($attributeName === $galleryAttribute) {
				$gallery = $this->galleryImages($attributeName, $values, $imageValues);
				if ($gallery !== []) {
					$item[$attributeName] = $gallery;
				}

				continue;
			}

			if ($values === []) {
				// A computed `sale_price` is null on every item not on promotion, and "Don't include" is blank on
				// all of them, so only a mapped attribute's blanks mean anything.
				if (! $isComputed && $this->isMapped($attributeName)) {
					$this->buildDiagnostics->countBlank($attributeName);
				}

				continue;
			}

			if ($attributeName === $imageAttribute) {
				$imageValues = $values;
			}

			$item[$attributeName] = $attributeDefinition->attributeKind === AttributeKind::CategoryList
				? $values
				: $values[0];
		}

		return $this->feedSpec->finalizeItem($item);
	}

	/**
	 * The first required attribute the item has no value for, and so the reason a build would skip it.
	 *
	 * @param array<string, string|list<string>> $item
	 */
	public function missingRequired(array $item): ?string
	{
		foreach ($this->feedSpec->requiredAttributes() as $attribute) {
			if (($item[$attribute] ?? '') === '') {
				return $attribute;
			}
		}

		return null;
	}

	/**
	 * Records that an element was excluded from the feed.
	 */
	public function recordSkip(ElementInterface $element, string $attribute): void
	{
		$this->buildDiagnostics->countSkipped($attribute);
		$this->buildDiagnostics->recordSkippedSample((int) $element->id, $attribute);
	}

	/**
	 * Renames the attributes the platform spells its own way, once the required check has run against the
	 * shared names.
	 *
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|list<string>>
	 */
	public function renameForDocument(array $item): array
	{
		$document = [];

		foreach ($item as $attribute => $value) {
			$document[$this->feedSpec->documentName($attribute)] = $value;
		}

		return $document;
	}

	/**
	 * What an attribute's mapping resolves to, falling back to its default. Public so the image test can
	 * resolve a URL exactly as a build would.
	 *
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	public function mappedValues(ElementInterface $element, AttributeDefinition $attributeDefinition): array
	{
		$mapping = $this->mappings[$attributeDefinition->name];
		$default = $mapping['default'];

		if (in_array($mapping['kind'], [Mapping::NO_INCLUDE, Mapping::IMAGE_OVERFLOW], true)) {
			return [];
		}

		$values = $mapping['kind'] === Mapping::USE_DEFAULT
			? []
			: $this->feedSource->resolve($element, $mapping, $attributeDefinition);

		if ($values === [] && $default !== '') {
			$values = $attributeDefinition->attributeKind === AttributeKind::Image
				? $this->feedSource->defaultImageUrl($default)
				: [$default];
		}

		$kind = $attributeDefinition->attributeKind;
		if ($kind === AttributeKind::Url || $kind === AttributeKind::Image) {
			$absolute = [];
			$firstDropped = null;

			foreach ($values as $value) {
				if (UrlHelper::isAbsoluteUrl($value)) {
					$absolute[] = $value;
				} else {
					$firstDropped ??= $value;
				}
			}

			// Counted once per item, matching the blank count it sits beside in the CP. Reported apart from a
			// blank because a value that would not resolve looks identical downstream to an unmapped attribute.
			if ($firstDropped !== null) {
				$this->buildDiagnostics->countRelativeUrl($attributeDefinition->name, $firstDropped);
			}

			return $absolute;
		}

		return $values;
	}

	/**
	 * An attribute's values for one element, formatted the way the platform wants them.
	 *
	 * @return list<string>
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function valuesFor(
		ElementInterface $element,
		AttributeDefinition $attributeDefinition,
		bool $isComputed,
	): array {
		$attributeName = $attributeDefinition->name;

		$values = $isComputed
			? $this->computedValues($this->feedSource->compute($element, $attributeName))
			: $this->mappedValues($element, $attributeDefinition);

		return $attributeDefinition->attributeKind === AttributeKind::Money
			? $this->formattedMoney($values, $attributeName)
			: $values;
	}

	/**
	 * @param list<string> $mappedValues resolved from the attribute's own source, empty for overflow
	 * @param list<string> $imageValues the main image source's full list
	 * @return list<string>
	 */
	private function galleryImages(string $galleryAttribute, array $mappedValues, array $imageValues): array
	{
		return $this->mappings[$galleryAttribute]['kind'] === Mapping::IMAGE_OVERFLOW
			? array_slice($imageValues, 1, FeedSpec::MAX_GALLERY_IMAGES)
			: array_slice($mappedValues, 0, FeedSpec::MAX_GALLERY_IMAGES);
	}

	private function isMapped(string $attribute): bool
	{
		return $this->mappings[$attribute]['kind'] !== Mapping::NO_INCLUDE;
	}

	/**
	 * @param list<string> $values bare decimals
	 * @return list<string>
	 */
	private function formattedMoney(array $values, string $attribute): array
	{
		$currency = $this->feed->getCurrency();
		if (! $currency instanceof Currency) {
			return [];
		}

		$formatted = [];

		foreach ($values as $value) {
			$money = FeedValue::moneyFromDecimal($value, $currency);
			if (! $money instanceof Money) {
				continue;
			}

			if (! $money->isPositive()) {
				$this->buildDiagnostics->countInvalid($attribute);
			}

			$formatted[] = $this->feedSpec->formatMoney($money);
		}

		return $formatted;
	}

	/**
	 * A computed attribute's non-empty values, as a list whatever the source returned.
	 *
	 * @param string|list<string>|null $value
	 * @return list<string>
	 */
	private function computedValues(string|array|null $value): array
	{
		if ($value === null) {
			return [];
		}

		$values = is_array($value) ? $value : [$value];

		return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
	}
}
