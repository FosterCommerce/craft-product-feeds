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
	 * One store row per variant, keyed by purchasable ID. `stock` has no setter on `Purchasable`, so it
	 * cannot reach the element as a selected column and `getStock()` would be a query per variant.
	 *
	 * @var array<int, array{stock: ?int, inventoryTracked: bool, availableForPurchase: bool}>
	 */
	private array $variantStock = [];

	/**
	 * @throws InvalidConfigException
	 */
	public function query(): ElementQueryInterface
	{
		return $this->baseQuery(true)
			// Catalog pricing rules can be scoped to a customer group. Google's crawler is logged
			// out, so the feed has to quote the logged-out price or the landing page won't match.
			->forCustomer(false)
			// `product` always: the default mapping reads its title and URL, and every `productField:`
			// mapping hangs off it.
			->with(['product', ...$this->eagerLoadPaths()])
			->orderBy([
				'elements.id' => SORT_ASC,
			]);
	}

	public function computedAttributes(): array
	{
		return ['id', 'item_group_id', 'price', 'sale_price', 'sale_price_effective_date', 'availability', 'inventory_quantity'];
	}

	public function prepareBatch(array $elements): void
	{
		$this->variantStock = [];

		$storeId = $this->feed->getStore()?->id;
		$purchasableIds = array_values(array_filter(array_map(
			static fn (ElementInterface $element): ?int => $element->id,
			$elements
		)));

		if ($storeId === null || $purchasableIds === []) {
			return;
		}

		/** @var list<array{purchasableId: int|string, stock: int|string|null, inventoryTracked: bool|int, availableForPurchase: bool|int}> $rows */
		$rows = (new Query())
			->select(['purchasableId', 'stock', 'inventoryTracked', 'availableForPurchase'])
			->from(CommerceTable::PURCHASABLES_STORES)
			->where([
				'storeId' => $storeId,
				'purchasableId' => $purchasableIds,
			])
			->all();

		foreach ($rows as $row) {
			$stock = $row['stock'];
			$this->variantStock[(int) $row['purchasableId']] = [
				'stock' => is_numeric($stock) ? (int) $stock : null,
				'inventoryTracked' => (bool) $row['inventoryTracked'],
				'availableForPurchase' => (bool) $row['availableForPurchase'],
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
			'price' => $this->decimalPrice($this->publishedPrice($element)),
			'sale_price' => $element->getOnPromotion() ? $this->decimalPrice($element->getPromotionalPrice()) : null,
			'sale_price_effective_date' => $this->salePriceEffectiveDate($element),
			'availability' => $this->availability($element),
			'inventory_quantity' => $this->inventoryQuantity($element),
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

	public function handles(ElementInterface $element): bool
	{
		return $element instanceof Variant || $element instanceof Product;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function mightContain(ElementInterface $element): bool
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
	public function contains(ElementInterface $element): bool
	{
		$query = $this->baseQuery(false);

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
		// The report only ever lists elements this source's own query returned.
		/** @var Variant $element */
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
	 * @throws InvalidConfigException
	 */
	protected function sourceName(string $sourceId): ?string
	{
		foreach ($this->productTypes() as $productType) {
			if ((string) $productType->id === $sourceId) {
				return (string) $productType->name;
			}
		}

		return null;
	}

	protected function productOf(ElementInterface $element): ?ElementInterface
	{
		return $element instanceof Variant ? $element->getProduct() : null;
	}

	/**
	 * Every variant this feed covers. Live only for a build: a disabled or unposted product has no landing
	 * page, and a feed item pointing at a 404 is a disapproval.
	 *
	 * @return VariantQuery<int, Variant>
	 * @throws InvalidConfigException
	 */
	private function baseQuery(bool $liveOnly): VariantQuery
	{
		$query = Variant::find()
			->siteId($this->feed->siteId)
			->status($liveOnly ? Element::STATUS_ENABLED : null)
			->productStatus($liveOnly ? Product::STATUS_LIVE : null);

		$query->typeId($this->productTypeIds());

		$condition = $this->filterCondition();
		if ($condition instanceof ElementCondition) {
			$productQuery = Product::find()->siteId($this->feed->siteId);
			if (! $liveOnly) {
				$productQuery->status(null);
			}

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
	 * A variant with no store row is treated as out of stock.
	 *
	 * @throws InvalidConfigException
	 */
	private function availability(Variant $variant): string
	{
		$variantStock = $this->variantStock[(int) $variant->id] ?? null;

		if ($variantStock === null || ! $variantStock['availableForPurchase']) {
			return Availability::OutOfStock->value;
		}

		if ($variantStock['inventoryTracked'] && ($variantStock['stock'] ?? 0) < 1) {
			return $this->allowsOutOfStockPurchases($variant)
				? Availability::InStock->value
				: Availability::OutOfStock->value;
		}

		return Availability::InStock->value;
	}

	/**
	 * Commerce keeps a backordered variant buyable, so out_of_stock would suppress an item the store is
	 * still selling. Commerce owns the rule, and an EVENT_PURCHASABLE_OUT_OF_STOCK_PURCHASES_ALLOWED
	 * handler can change it per store, so this asks rather than reading the column.
	 *
	 * @throws InvalidConfigException
	 */
	private function allowsOutOfStockPurchases(Variant $variant): bool
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return $commerce->getPurchasables()->isPurchasableOutOfStockPurchasingAllowed($variant);
	}

	/**
	 * A spec with no `sale_price` attribute has nowhere else to put a promotion, so `price` carries what
	 * the customer actually pays. Where the spec has one, `price` stays the full price and the promotion
	 * goes to `sale_price`.
	 *
	 * @throws InvalidConfigException
	 */
	private function publishedPrice(Variant $variant): ?float
	{
		if (! $variant->getOnPromotion() || $this->feed->getSpec()->separatesSalePrice()) {
			return $variant->getPrice();
		}

		return $variant->getPromotionalPrice();
	}

	/**
	 * An untracked variant has no stock number to send, and a zero would read as out of stock on a
	 * platform that hides items by their quantity.
	 */
	private function inventoryQuantity(Variant $variant): ?string
	{
		$variantStock = $this->variantStock[(int) $variant->id] ?? null;

		return $variantStock === null || ! $variantStock['inventoryTracked']
			? null
			: (string) ($variantStock['stock'] ?? 0);
	}

	private function decimalPrice(?float $price): ?string
	{
		return $price === null ? null : (string) $price;
	}

	/**
	 * Catalog pricing rules do not expose a start and end date on the element, so a store using them
	 * sends no sale window at all. Only the older Sales system carries the dates.
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
