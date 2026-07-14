<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\sources;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\conditions\ElementCondition;
use craft\elements\db\ElementQueryInterface;
use craft\models\FieldLayout;
use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\enums\Source;
use fostercommerce\productfeeds\feeds\AttributeDefinition;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\helpers\FeedValue;
use fostercommerce\productfeeds\helpers\FilterCondition;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\ImageTransform;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Supplies a feed's items: the query that finds them, and the attributes derived from the element
 * rather than mapped by hand.
 */
abstract class FeedSource
{
	/**
	 * @var array<string, list<string>>
	 */
	private array $defaultImageUrls = [];

	private ?ImageTransform $imageTransform = null;

	private readonly FeedValue $feedValue;

	public function __construct(
		protected readonly Feed $feed,
	) {
		$this->feedValue = new FeedValue();
	}

	public static function forFeed(Feed $feed): self
	{
		return match ($feed->getSource()) {
			Source::Variants => new VariantSource($feed),
			Source::Entries => new EntrySource($feed),
		};
	}

	/**
	 * @throws InvalidConfigException
	 */
	abstract public function query(): ElementQueryInterface;

	/**
	 * Attributes this source derives. Everything else the store admin maps.
	 *
	 * @return string[]
	 */
	abstract public function computedAttributes(): array;

	/**
	 * The attributes the admin maps.
	 *
	 * @return array<string, AttributeDefinition> keyed by attribute handle
	 */
	public function mappableAttributes(FeedSpec $spec): array
	{
		$pluginProvided = [...$this->computedAttributes(), ...$spec->derivedAttributes()];

		return array_filter(
			$spec->attributes(),
			static fn (string $name): bool => ! in_array($name, $pluginProvided, true),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * @return string|list<string>|null
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	abstract public function compute(ElementInterface $element, string $attribute): string|array|null;

	/**
	 * Field layouts whose custom fields the mapping dropdown offers, keyed by the mapping prefix
	 * they'd be stored under.
	 *
	 * @return array<string, FieldLayout[]>
	 * @throws InvalidConfigException
	 */
	abstract public function fieldLayouts(): array;

	/**
	 * @return array<string, string> mapping prefix => translation key
	 */
	abstract public function fieldGroupLabels(): array;

	/**
	 * @return array<string, array<string, string>> group translation key => (path => label)
	 */
	abstract public function elementPaths(): array;

	/**
	 * The source keys the admin may choose. Only the ones whose items have a public URL on this feed's
	 * site: an item needs a landing page for Google to crawl.
	 *
	 * @return list<array{heading: string, options: list<array{value: string, label: string}>}>
	 * @throws InvalidConfigException
	 */
	abstract public function selectableSourceGroups(): array;

	/**
	 * The same keys, flat.
	 *
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	public function selectableSourceIds(): array
	{
		$sourceIds = [];

		foreach ($this->selectableSourceGroups() as $group) {
			foreach ($group['options'] as $option) {
				$sourceIds[] = $option['value'];
			}
		}

		return $sourceIds;
	}

	/**
	 * The picked sources this feed can't produce items for. A non-empty list blocks the feed from saving.
	 *
	 * @return list<string> names of the offending product types or sections
	 * @throws InvalidConfigException
	 */
	public function sourcesWithoutUrls(): array
	{
		if ($this->feed->sourceIds === []) {
			return [];
		}

		$withUrls = $this->selectableSourceIds();
		$names = [];

		foreach ($this->feed->sourceIds as $sourceId) {
			if (! in_array($sourceId, $withUrls, true)) {
				$names[] = $this->sourceName($sourceId);
			}
		}

		return array_values(array_unique(array_filter($names)));
	}

	/**
	 * @return class-string<ElementInterface>
	 */
	abstract public function elementType(): string;

	/**
	 * Whether the element is of a type this source feeds at all.
	 */
	abstract public function handles(ElementInterface $element): bool;

	/**
	 * Whether the element belongs to this feed's set, filter included, ignoring enabled status so a
	 * just-disabled member still counts and its removal triggers a rebuild.
	 *
	 * @throws InvalidConfigException
	 */
	abstract public function contains(ElementInterface $element): bool;

	/**
	 * Whether a deleted element probably belonged to this feed, judged from the element in memory: a
	 * trashed element no longer comes back from a query, so `contains()` cannot test it. The filter is not
	 * applied, so deleting a filtered-out member still rebuilds.
	 *
	 * @throws InvalidConfigException
	 */
	abstract public function mightContain(ElementInterface $element): bool;

	/**
	 * The element the filter condition is built against. Variant fields live on the product, so a
	 * variant feed filters by its product; an entry feed filters itself.
	 *
	 * @return class-string<ElementInterface>
	 */
	abstract public function conditionElementType(): string;

	/**
	 * One excluded item as a CSV row.
	 *
	 * @return array{id: string, title: string, cpUrl: string, issue: string}
	 */
	abstract public function reportRow(ElementInterface $element, string $issue): array;

	/**
	 * The keys this feed reads. An empty selection means every source that can work, not every source.
	 *
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	public function effectiveSourceIds(): array
	{
		return $this->feed->sourceIds !== []
			? array_values($this->feed->sourceIds)
			: $this->selectableSourceIds();
	}

	/**
	 * The mapping a new feed starts with.
	 *
	 * @return array<string, array{source: string, default: string}>
	 */
	abstract public function defaultMapping(): array;

	/**
	 * Called once per batch so a source can bulk-load anything the element query won't carry.
	 *
	 * @param ElementInterface[] $elements
	 */
	public function prepareBatch(array $elements): void
	{
	}

	/**
	 * @param array{kind: string, value: string} $mapping parsed by `Mapping::parse()`
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	public function resolve(ElementInterface $element, array $mapping, AttributeDefinition $attributeDefinition): array
	{
		$value = match ($mapping['kind']) {
			Mapping::ELEMENT => $this->elementValue($element, $mapping['value']),
			Mapping::FIELD => $this->fieldValue($element, $mapping['value']),
			Mapping::PRODUCT_FIELD => $this->fieldValue($this->productOf($element), $mapping['value']),
			default => null,
		};

		return $this->feedValue->normalize(
			$value,
			$attributeDefinition->attributeKind,
			$this->imageTransform(),
			$attributeDefinition->maxLength
		);
	}

	/**
	 * An image default is an asset ID, so it resolves through the feed's engine like any other image.
	 *
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	public function defaultImageUrl(string $assetId): array
	{
		if (! isset($this->defaultImageUrls[$assetId])) {
			$asset = Craft::$app->getAssets()->getAssetById((int) $assetId);

			$this->defaultImageUrls[$assetId] = $asset === null
				? []
				: $this->feedValue->normalize($asset, AttributeKind::Image, $this->imageTransform());
		}

		return $this->defaultImageUrls[$assetId];
	}

	/**
	 * What to call a source key on screen. Null where the key no longer resolves to anything.
	 *
	 * @throws InvalidConfigException
	 */
	abstract protected function sourceName(string $sourceId): ?string;

	/**
	 * The custom fields the mapping reads, so the query loads them in one go rather than per element.
	 *
	 * @return list<string>
	 */
	protected function eagerLoadPaths(): array
	{
		$paths = [];

		foreach ($this->feed->fieldMapping as $mapping) {
			$parsed = Mapping::parse($mapping['source']);

			$path = match ($parsed['kind']) {
				Mapping::FIELD => $parsed['value'],
				Mapping::PRODUCT_FIELD => 'product.' . $parsed['value'],
				default => null,
			};

			if ($path !== null) {
				$paths[] = $path;
			}
		}

		return array_values(array_unique($paths));
	}

	/**
	 * The element a `productField:` mapping reads from. Null where the concept doesn't apply.
	 */
	protected function productOf(ElementInterface $element): ?ElementInterface
	{
		return null;
	}

	protected function filterCondition(): ?ElementCondition
	{
		if ($this->feed->filterCondition === []) {
			return null;
		}

		return FilterCondition::fromConfig($this->conditionElementType(), $this->feed->filterCondition);
	}

	private function imageTransform(): ImageTransform
	{
		return $this->imageTransform ??= ImageTransform::fromFeed($this->feed);
	}

	/**
	 * Walks a dotted path such as `product.url`. A missing segment yields null, counted as a blank rather
	 * than an error: a mapped field can be absent from some of a feed's product types.
	 */
	private function elementValue(ElementInterface $element, string $path): mixed
	{
		$value = $element;

		foreach (explode('.', $path) as $segment) {
			if (! is_object($value)) {
				return null;
			}

			$getter = 'get' . ucfirst($segment);
			if (method_exists($value, $getter)) {
				$value = $value->{$getter}();
				continue;
			}

			if (! isset($value->{$segment})) {
				return null;
			}

			$value = $value->{$segment};
		}

		return $value;
	}

	private function fieldValue(?ElementInterface $element, string $handle): mixed
	{
		if (! $element instanceof ElementInterface) {
			return null;
		}

		return $element->getFieldLayout()?->getFieldByHandle($handle) instanceof FieldInterface
			? $element->getFieldValue($handle)
			: null;
	}
}
