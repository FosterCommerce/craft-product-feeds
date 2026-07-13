<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\sources;

use craft\base\Element;
use craft\base\ElementInterface;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\Sale;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\conditions\ElementCondition;
use craft\elements\db\ElementQueryInterface;
use DateTime;
use DateTimeInterface;
use fostercommerce\productfeeds\enums\Availability;
use fostercommerce\productfeeds\helpers\Mapping;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Commerce variants. One feed item per variant, grouped into products by `item_group_id`.
 */
class VariantSource extends FeedSource
{
	/**
	 * Availability facts, keyed by purchasable ID. `stock` has no setter on `Purchasable`, so a
	 * selected column can't reach the element and `getStock()` would be a query per variant.
	 *
	 * @var array<int, array{stock: ?int, inventoryTracked: bool, availableForPurchase: bool}>
	 */
	private array $availability = [];

	/**
	 * @throws InvalidConfigException
	 */
	public function query(): ElementQueryInterface
	{
		$query = Variant::find()
			->siteId($this->feed->siteId)
			->status(Element::STATUS_ENABLED)
			// A disabled or unposted product has no landing page, and a feed item pointing at a 404
			// is a disapproval.
			->productStatus(Product::STATUS_LIVE)
			// Catalog pricing rules can be scoped to a customer group. Google's crawler is logged
			// out, so the feed has to quote the logged-out price or the landing page won't match.
			->forCustomer(false)
			->with($this->eagerLoadPaths())
			->orderBy([
				'elements.id' => SORT_ASC,
			]);

		$query->typeId($this->productTypeIds());

		$condition = $this->filterCondition();
		if ($condition instanceof ElementCondition) {
			$productQuery = Product::find()->siteId($this->feed->siteId);
			$condition->modifyQuery($productQuery);
			$query->hasProduct($productQuery);
		}

		return $query;
	}

	public function computedAttributes(): array
	{
		return ['id', 'item_group_id', 'price', 'sale_price', 'sale_price_effective_date', 'availability'];
	}

	public function prepareBatch(array $elements): void
	{
		$this->availability = [];

		$storeId = $this->feed->getStore()?->id;
		$purchasableIds = array_values(array_filter(array_map(
			static fn (ElementInterface $element): ?int => $element->id,
			$elements
		)));

		if ($storeId === null || $purchasableIds === []) {
			return;
		}

		$rows = (new Query())
			->select(['purchasableId', 'stock', 'inventoryTracked', 'availableForPurchase'])
			->from(CommerceTable::PURCHASABLES_STORES)
			->where([
				'storeId' => $storeId,
				'purchasableId' => $purchasableIds,
			])
			->all();

		foreach ($rows as $row) {
			if (! is_array($row)) {
				continue;
			}

			$stock = $row['stock'] ?? null;
			$this->availability[(int) $row['purchasableId']] = [
				'stock' => is_numeric($stock) ? (int) $stock : null,
				'inventoryTracked' => (bool) ($row['inventoryTracked'] ?? false),
				'availableForPurchase' => (bool) ($row['availableForPurchase'] ?? false),
			];
		}
	}

	/**
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function compute(ElementInterface $element, string $attribute): string|array|null
	{
		if (! $element instanceof Variant) {
			return null;
		}

		return match ($attribute) {
			'id' => $element->getSku(),
			'item_group_id' => (string) $element->getProductId(),
			'price' => $this->decimalPrice($element->getPrice()),
			'sale_price' => $element->getOnPromotion() ? $this->decimalPrice($element->getPromotionalPrice()) : null,
			'sale_price_effective_date' => $this->salePriceEffectiveDate($element),
			'availability' => $this->availability($element),
			default => null,
		};
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function fieldLayouts(): array
	{
		$variantLayouts = [];
		$productLayouts = [];

		foreach ($this->productTypes() as $productType) {
			$variantLayouts[] = $productType->getVariantFieldLayout();
			$productLayouts[] = $productType->getFieldLayout();
		}

		return [
			Mapping::FIELD => $variantLayouts,
			Mapping::PRODUCT_FIELD => $productLayouts,
		];
	}

	public function elementType(): string
	{
		return Variant::class;
	}

	public function conditionElementType(): string
	{
		return Product::class;
	}

	public function reads(ElementInterface $element): bool
	{
		return $element instanceof Variant || $element instanceof Product;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function mightRead(ElementInterface $element): bool
	{
		$typeId = match (true) {
			$element instanceof Product => $element->typeId,
			$element instanceof Variant => $element->getProduct()?->typeId,
			default => null,
		};

		return $typeId !== null && in_array($typeId, $this->productTypeIds(), true);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function inScope(ElementInterface $element): bool
	{
		$query = $this->scopeQuery();

		if ($element instanceof Variant) {
			$query->id($element->id);
		} elseif ($element instanceof Product) {
			$query->productId($element->id);
		} else {
			return false;
		}

		return $query->exists();
	}

	public function reportRow(ElementInterface $element, string $issue): array
	{
		if (! $element instanceof Variant) {
			return [
				'id' => '',
				'title' => '',
				'cpUrl' => '',
				'issue' => $issue,
			];
		}

		$product = $element->getProduct();

		return [
			'id' => $element->getSku(),
			'title' => trim(sprintf('%s %s', $product?->title ?? '', $element->title ?? '')),
			'cpUrl' => $product?->getCpEditUrl() ?? '',
			'issue' => $issue,
		];
	}

	public function fieldGroupLabels(): array
	{
		return [
			Mapping::FIELD => 'mapping.variantFields',
			Mapping::PRODUCT_FIELD => 'mapping.productFields',
		];
	}

	public function defaultMapping(): array
	{
		return [
			'title' => [
				'source' => Mapping::build(Mapping::ELEMENT, 'product.title'),
				'default' => '',
			],
			'link' => [
				'source' => Mapping::build(Mapping::ELEMENT, 'product.url'),
				'default' => '',
			],
			'condition' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => 'new',
			],
		];
	}

	public function elementPaths(): array
	{
		return [
			'mapping.variantProperties' => [
				'sku' => 'SKU',
				'title' => 'Variant title',
			],
			'mapping.productProperties' => [
				'product.title' => 'Product title',
				'product.url' => 'Product URL',
				'product.slug' => 'Product slug',
			],
		];
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function selectableSourceIds(): array
	{
		return array_map(
			static fn (ProductType $productType): string => (string) $productType->id,
			$this->productTypesWithUrls()
		);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function selectableSourceGroups(): array
	{
		$options = [];

		foreach ($this->productTypesWithUrls() as $productType) {
			$options[] = [
				'value' => (string) $productType->id,
				'label' => (string) $productType->name,
			];
		}

		return [[
			'heading' => '',
			'options' => $options,
		]];
	}

	/**
	 * Only reports explicitly chosen product types. An empty choice means "everything that can
	 * work", which `effectiveSourceIds()` already narrows to the ones with URLs.
	 *
	 * @throws InvalidConfigException
	 */
	public function sourcesWithoutUrls(): array
	{
		if ($this->feed->sourceIds === []) {
			return [];
		}

		$withUrls = $this->selectableSourceIds();
		$names = [];

		foreach ($this->productTypes() as $productType) {
			if (! in_array((string) $productType->id, $withUrls, true)) {
				$names[] = (string) $productType->name;
			}
		}

		return $names;
	}

	protected function productOf(ElementInterface $element): ?ElementInterface
	{
		return $element instanceof Variant ? $element->getProduct() : null;
	}

	/**
	 * @return VariantQuery<int, Variant>
	 * @throws InvalidConfigException
	 */
	private function scopeQuery(): VariantQuery
	{
		$query = Variant::find()
			->siteId($this->feed->siteId)
			->status(null)
			->productStatus(null);
		$query->typeId($this->productTypeIds());

		$condition = $this->filterCondition();
		if ($condition instanceof ElementCondition) {
			$productQuery = Product::find()->siteId($this->feed->siteId)->status(null);
			$condition->modifyQuery($productQuery);
			$query->hasProduct($productQuery);
		}

		return $query;
	}

	/**
	 * @return list<ProductType>
	 * @throws InvalidConfigException
	 */
	private function productTypesWithUrls(): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$withUrls = [];

		foreach ($commerce->getProductTypes()->getAllProductTypes() as $productType) {
			$siteSettings = $productType->getSiteSettings()[$this->feed->siteId] ?? null;
			if ($productType->id !== null && $siteSettings !== null && $siteSettings->hasUrls) {
				$withUrls[] = $productType;
			}
		}

		return $withUrls;
	}

	/**
	 * @return list<ProductType>
	 * @throws InvalidConfigException
	 */
	private function productTypes(): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$productTypes = $commerce->getProductTypes()->getAllProductTypes();
		$sourceIds = $this->effectiveSourceIds();

		return array_values(array_filter(
			$productTypes,
			static fn (ProductType $productType): bool => in_array((string) $productType->id, $sourceIds, true)
		));
	}

	/**
	 * @return list<int>
	 * @throws InvalidConfigException
	 */
	private function productTypeIds(): array
	{
		return array_values(array_map(
			intval(...),
			array_filter($this->effectiveSourceIds(), is_numeric(...))
		));
	}

	/**
	 * `product` is always eager-loaded: the default mapping reads the product's title and URL, and every
	 * `productField:` mapping hangs off it.
	 *
	 * @return string[]
	 */
	private function eagerLoadPaths(): array
	{
		$paths = ['product'];

		foreach ($this->feed->fieldMapping as $mapping) {
			$parsed = Mapping::parse($mapping['source']);
			if ($parsed['kind'] !== Mapping::FIELD && $parsed['kind'] !== Mapping::PRODUCT_FIELD) {
				continue;
			}

			$paths[] = $parsed['kind'] === Mapping::PRODUCT_FIELD
				? 'product.' . $parsed['value']
				: $parsed['value'];
		}

		return array_values(array_unique($paths));
	}

	private function availability(Variant $element): string
	{
		$facts = $this->availability[(int) $element->id] ?? null;

		if ($facts === null || ! $facts['availableForPurchase']) {
			return Availability::OutOfStock->value;
		}

		if ($facts['inventoryTracked'] && ($facts['stock'] ?? 0) < 1) {
			return Availability::OutOfStock->value;
		}

		return Availability::InStock->value;
	}

	private function decimalPrice(?float $price): ?string
	{
		return $price === null ? null : (string) $price;
	}

	/**
	 * Only the Sales system exposes a promotion window on the element. Catalog pricing rules store their
	 * dates on the `commerce_catalogpricing` row that sets the price, but Commerce's element API hands
	 * back only the `MIN()`-aggregated price with those dates grouped out, so the attribute is omitted.
	 *
	 * @throws InvalidConfigException
	 */
	private function salePriceEffectiveDate(Variant $element): ?string
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		if ($commerce->getCatalogPricingRules()->canUseCatalogPricingRules()) {
			return null;
		}

		if (! $element->getOnPromotion()) {
			return null;
		}

		$start = null;
		$end = null;

		foreach ($element->getSales() as $sale) {
			if (! $sale instanceof Sale) {
				continue;
			}

			if ($sale->dateFrom instanceof DateTime && (! $start instanceof DateTime || $sale->dateFrom > $start)) {
				$start = $sale->dateFrom;
			}

			if ($sale->dateTo instanceof DateTime && (! $end instanceof DateTime || $sale->dateTo < $end)) {
				$end = $sale->dateTo;
			}
		}

		// Google requires both ends of the interval.
		if (! $start instanceof DateTime || ! $end instanceof DateTime) {
			return null;
		}

		return sprintf('%s/%s', $start->format(DateTimeInterface::ATOM), $end->format(DateTimeInterface::ATOM));
	}
}
