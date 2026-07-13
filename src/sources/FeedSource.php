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
use fostercommerce\productfeeds\helpers\FeedValue;
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
		// A source is built fresh for each build, preview and image test, so what it memoizes cannot
		// outlive the pass that filled it.
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
	 * The sources this feed can't produce items for, e.g. a product type with no public URL. A
	 * non-empty list blocks the feed from saving.
	 *
	 * @return array<int, string> names of the offending product types or sections
	 * @throws InvalidConfigException
	 */
	abstract public function sourcesWithoutUrls(): array;

	/**
	 * Every source key the admin may choose. Only those whose items have a public URL on this feed's
	 * site: an item needs a landing page for Google to crawl.
	 *
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	abstract public function selectableSourceIds(): array;

	/**
	 * The same keys, as the picker shows them. A heading groups the keys under the thing they belong
	 * to, and is empty where the source has nothing to group by.
	 *
	 * @return list<array{heading: string, options: list<array{value: string, label: string}>}>
	 * @throws InvalidConfigException
	 */
	abstract public function selectableSourceGroups(): array;

	/**
	 * @return class-string<ElementInterface>
	 */
	abstract public function elementType(): string;

	/**
	 * Whether a saved or deleted element is one this source could feed. Checked before any query, to
	 * skip unrelated element types.
	 */
	abstract public function reads(ElementInterface $element): bool;

	/**
	 * Whether the element belongs to this feed's set, filter included, ignoring enabled status so a
	 * just-disabled member still counts and its removal triggers a rebuild.
	 *
	 * @throws InvalidConfigException
	 */
	abstract public function inScope(ElementInterface $element): bool;

	/**
	 * A coarse membership test from the element's in-memory type or section, for a deleted element that
	 * an element query no longer returns once it is trashed, so `inScope` cannot test it. The filter is
	 * not applied, so deleting a filtered-out member still rebuilds.
	 *
	 * @throws InvalidConfigException
	 */
	abstract public function mightRead(ElementInterface $element): bool;

	/**
	 * The element the filter condition is built against. Variant fields live on the product, so a
	 * variant feed filters by its product; an entry feed filters itself.
	 *
	 * @return class-string<ElementInterface>
	 */
	abstract public function conditionElementType(): string;

	/**
	 * One excluded product as a CSV row. `cpUrl` is the element's edit screen: a variant has none of
	 * its own, so it points at the product that owns it.
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
	 * @return array<string, array{source: string, default: string}>
	 */
	public function defaultMapping(): array
	{
		return [];
	}

	/**
	 * Called once per batch so a source can bulk-load anything the element query won't carry.
	 *
	 * @param ElementInterface[] $elements
	 */
	public function prepareBatch(array $elements): void
	{
	}

	/**
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	public function resolve(ElementInterface $element, string $source, AttributeDefinition $attributeDefinition): array
	{
		$parsed = Mapping::parse($source);

		$value = match ($parsed['kind']) {
			Mapping::ELEMENT => $this->elementValue($element, $parsed['value']),
			Mapping::FIELD => $this->fieldValue($element, $parsed['value']),
			Mapping::PRODUCT_FIELD => $this->fieldValue($this->productOf($element), $parsed['value']),
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
	 * An image default is an asset ID rather than a value, so it resolves through the feed's engine like
	 * any other image.
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

		$config = $this->feed->filterCondition;
		unset($config['class']);

		return new ElementCondition($this->conditionElementType(), $config);
	}

	private function imageTransform(): ImageTransform
	{
		return $this->imageTransform ??= ImageTransform::fromFeed($this->feed);
	}

	/**
	 * Walks a dotted path such as `product.url`. A missing segment yields null, which the builder counts
	 * as a blank rather than an error: a mapped field can be absent from some of a feed's product types.
	 */
	private function elementValue(?ElementInterface $element, string $path): mixed
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
