<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\sources;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\db\ProductQuery;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\db\ElementQueryInterface;
use fostercommerce\productfeeds\enums\Availability;
use fostercommerce\productfeeds\feeds\AttributeDefinition;
use fostercommerce\productfeeds\helpers\FeedValue;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use Money\Currency;
use Money\Money;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Commerce products. One feed item per product, for catalogs that list a product once rather than each
 * of its variants.
 *
 * Price and stock are mapped to a calculation over the product's variants, so the admin chooses which.
 */
class ProductSource extends FeedSource
{
	private const LOWEST_VARIANT_PRICE = 'lowestVariantPrice';

	private const DEFAULT_VARIANT_PRICE = 'defaultVariantPrice';

	private const HIGHEST_VARIANT_PRICE = 'highestVariantPrice';

	private const LOWEST_VARIANT_SALE_PRICE = 'lowestVariantSalePrice';

	private const DEFAULT_VARIANT_SALE_PRICE = 'defaultVariantSalePrice';

	private const ANY_VARIANT_IN_STOCK = 'anyVariantInStock';

	private const DEFAULT_VARIANT_AVAILABILITY = 'defaultVariantAvailability';

	private const TOTAL_VARIANT_STOCK = 'totalVariantStock';

	private const DEFAULT_VARIANT_STOCK = 'defaultVariantStock';

	/**
	 * The element paths each attribute offers on top of the product's own, resolved by `calculate()`.
	 */
	private const CALCULATIONS = [
		'price' => [
			self::LOWEST_VARIANT_PRICE => 'Lowest variant price',
			self::DEFAULT_VARIANT_PRICE => 'Default variant price',
			self::HIGHEST_VARIANT_PRICE => 'Highest variant price',
		],
		'sale_price' => [
			self::LOWEST_VARIANT_SALE_PRICE => 'Lowest-priced variant’s sale price',
			self::DEFAULT_VARIANT_SALE_PRICE => 'Default variant’s sale price',
		],
		'availability' => [
			self::ANY_VARIANT_IN_STOCK => 'In stock when any variant is',
			self::DEFAULT_VARIANT_AVAILABILITY => 'Default variant’s availability',
		],
		'inventory_quantity' => [
			self::TOTAL_VARIANT_STOCK => 'Total across variants',
			self::DEFAULT_VARIANT_STOCK => 'Default variant’s stock',
		],
	];

	/**
	 * Works out each variant's price, availability and stock, so a product's figures follow the same rules
	 * as a variant feed's items.
	 */
	private readonly VariantSource $variantSource;

	/**
	 * Price and sale price each read the sorted variants, so sort them once per product.
	 *
	 * @var array<int, list<Variant>>
	 */
	private array $variantsByPrice = [];

	public function __construct(Feed $feed)
	{
		parent::__construct($feed);

		$this->variantSource = new VariantSource($feed);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function query(): ElementQueryInterface
	{
		return $this->baseQuery(true)
			->with($this->eagerLoadPaths())
			->orderBy([
				'elements.id' => SORT_ASC,
			]);
	}

	public function twigVariables(): array
	{
		return [
			'object' => Craft::t(ProductFeeds::HANDLE, 'twig.product'),
		];
	}

	public function computedAttributes(): array
	{
		return ['id'];
	}

	public function compute(ElementInterface $element, string $attribute): string|array|null
	{
		return $attribute === 'id' ? (string) $element->id : null;
	}

	public function prepareBatch(array $elements): void
	{
		/** @var Product[] $elements */
		$products = [];
		foreach ($elements as $product) {
			$products[(int) $product->id] = $product;
		}

		$this->variantsByPrice = [];

		// Skip the query on an empty batch, since an empty productId matches every variant
		$variants = $products === [] ? [] : Variant::find()
			->productId(array_keys($products))
			->siteId($this->feed->siteId)
			->status(null)
			// Quote the logged-out price, since catalog pricing rules can be scoped to a customer group
			->forCustomer(false)
			->all();

		$variantsByProduct = array_fill_keys(array_keys($products), []);
		foreach ($variants as $variant) {
			$product = $products[(int) $variant->getOwnerId()];

			// Reuse the batch's products as owners, saving an owner query per batch
			$variant->setOwner($product);
			$variantsByProduct[(int) $product->id][] = $variant;
		}

		// Set an empty list too, so reading a product's variants later doesn't query them again
		foreach ($variantsByProduct as $productId => $productVariants) {
			$products[$productId]->setVariants($productVariants);
		}

		$this->variantSource->prepareBatch($variants);
	}

	/**
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function resolve(ElementInterface $element, array $mapping, AttributeDefinition $attributeDefinition): array
	{
		if ($mapping['kind'] !== Mapping::ELEMENT || ! $element instanceof Product || ! $this->isCalculatedPath($mapping['value'])) {
			return parent::resolve($element, $mapping, $attributeDefinition);
		}

		return $this->normalizeValue($this->calculate($element, $mapping['value']), $attributeDefinition);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function fieldLayouts(): array
	{
		return [
			Mapping::FIELD => $this->variantSource->fieldLayouts()[Mapping::PRODUCT_FIELD],
		];
	}

	public function elementType(): string
	{
		return Product::class;
	}

	public function conditionElementType(): string
	{
		return Product::class;
	}

	/**
	 * Variants count, since a product's price and stock come from them.
	 */
	public function handles(ElementInterface $element): bool
	{
		return $element instanceof Product || $element instanceof Variant;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function mightContain(ElementInterface $element): bool
	{
		return $this->variantSource->mightContain($element);
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function contains(ElementInterface $element): bool
	{
		$productId = match (true) {
			$element instanceof Product => $element->id,
			$element instanceof Variant => $element->getOwnerId(),
			default => null,
		};

		return $productId !== null && $this->baseQuery(false)->id($productId)->exists();
	}

	public function reportRow(ElementInterface $element, string $issue): array
	{
		return [
			'id' => (string) $element->id,
			'title' => $element->title ?? '',
			'cpUrl' => $element->getCpEditUrl() ?? '',
			'issue' => $issue,
		];
	}

	public function fieldGroupLabels(): array
	{
		return [
			Mapping::FIELD => 'mapping.productFields',
		];
	}

	public function defaultMapping(): array
	{
		return [
			'title' => [
				'source' => Mapping::build(Mapping::ELEMENT, 'title'),
				'default' => '',
			],
			'link' => [
				'source' => Mapping::build(Mapping::ELEMENT, 'url'),
				'default' => '',
			],
			'price' => [
				'source' => Mapping::build(Mapping::ELEMENT, self::LOWEST_VARIANT_PRICE),
				'default' => '',
			],
			'sale_price' => [
				'source' => Mapping::build(Mapping::ELEMENT, self::LOWEST_VARIANT_SALE_PRICE),
				'default' => '',
			],
			'availability' => [
				'source' => Mapping::build(Mapping::ELEMENT, self::ANY_VARIANT_IN_STOCK),
				'default' => '',
			],
			'inventory_quantity' => [
				'source' => Mapping::build(Mapping::ELEMENT, self::TOTAL_VARIANT_STOCK),
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
			'mapping.productProperties' => [
				'title' => 'Product title',
				'url' => 'Product URL',
				'slug' => 'Product slug',
				'defaultVariant.sku' => 'Default variant SKU',
			],
		];
	}

	public function elementPathsFor(string $attribute): array
	{
		return [
			'mapping.variantCalculations' => self::CALCULATIONS[$attribute] ?? [],
			...$this->elementPaths(),
		];
	}

	public function isCalculatedPath(string $path): bool
	{
		return array_key_exists($path, array_merge(...array_values(self::CALCULATIONS)));
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function selectableSourceGroups(): array
	{
		return $this->variantSource->selectableSourceGroups();
	}

	/**
	 * @throws InvalidConfigException
	 */
	protected function sourceName(string $sourceId): ?string
	{
		return $this->variantSource->sourceName($sourceId);
	}

	/**
	 * Every product this feed covers. Live only for a build: a disabled or unposted product has no landing
	 * page.
	 *
	 * @return ProductQuery<int, Product>
	 * @throws InvalidConfigException
	 */
	private function baseQuery(bool $liveOnly): ProductQuery
	{
		$query = Product::find()
			->siteId($this->feed->siteId)
			->status($liveOnly ? Product::STATUS_LIVE : null)
			->typeId($this->productTypeIds());

		$this->filterCondition()?->modifyQuery($query);

		return $query;
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
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function calculate(Product $product, string $path): ?string
	{
		$defaultVariant = $product->getDefaultVariant();

		return match ($path) {
			self::LOWEST_VARIANT_PRICE => $this->variantValue($this->variantsByPrice($product)[0] ?? null, 'price'),
			self::HIGHEST_VARIANT_PRICE => $this->variantValue(array_reverse($this->variantsByPrice($product))[0] ?? null, 'price'),
			self::DEFAULT_VARIANT_PRICE => $this->variantValue($defaultVariant, 'price'),
			// Use the lowest-priced variant, so the sale price is below the lowest price
			self::LOWEST_VARIANT_SALE_PRICE => $this->belowPublishedPrice($product, $this->variantValue($this->variantsByPrice($product)[0] ?? null, 'sale_price')),
			self::DEFAULT_VARIANT_SALE_PRICE => $this->belowPublishedPrice($product, $this->variantValue($defaultVariant, 'sale_price')),
			self::ANY_VARIANT_IN_STOCK => $this->anyVariantInStock($product),
			self::DEFAULT_VARIANT_AVAILABILITY => $this->variantValue($defaultVariant, 'availability'),
			self::TOTAL_VARIANT_STOCK => $this->totalVariantStock($product),
			self::DEFAULT_VARIANT_STOCK => $this->variantValue($defaultVariant, 'inventory_quantity'),
			default => null,
		};
	}

	/**
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function variantValue(?Variant $variant, string $attribute): ?string
	{
		if (! $variant instanceof Variant) {
			return null;
		}

		$value = $this->variantSource->compute($variant, $attribute);

		return is_string($value) ? $value : null;
	}

	/**
	 * A sale price must be lower than the price the item publishes, as on a variant feed. Only a calculated
	 * price is checked, since a field, default or Twig price resolves outside this source.
	 *
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function belowPublishedPrice(Product $product, ?string $salePrice): ?string
	{
		$priceMapping = Mapping::parse($this->feed->mappingSource('price', $this->feed->getSpec()));
		// Check price calculations only, since a sale calculation as the price would call back into this check
		if ($salePrice === null || $priceMapping['kind'] !== Mapping::ELEMENT || ! isset(self::CALCULATIONS['price'][$priceMapping['value']])) {
			return $salePrice;
		}

		$saleMoney = $this->money($salePrice);
		$priceMoney = $this->money($this->calculate($product, $priceMapping['value']));

		return $saleMoney instanceof Money && $priceMoney instanceof Money && $saleMoney->lessThan($priceMoney) ? $salePrice : null;
	}

	/**
	 * The variants in stock, lowest price first. When no variant is in stock, every variant is used, so the
	 * item still has a price.
	 *
	 * @return list<Variant>
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function variantsByPrice(Product $product): array
	{
		$productId = (int) $product->id;
		if (isset($this->variantsByPrice[$productId])) {
			return $this->variantsByPrice[$productId];
		}

		$variants = $product->getVariants()->all();
		$inStockVariants = array_filter($variants, $this->isInStock(...));

		$pricedVariants = [];
		foreach ($inStockVariants !== [] ? $inStockVariants : $variants as $variant) {
			$price = $this->money($this->variantValue($variant, 'price'));
			if ($price instanceof Money) {
				$salePrice = $this->money($this->variantValue($variant, 'sale_price')) ?? $price;
				$pricedVariants[] = [$price, $salePrice, $variant];
			}
		}

		// Break a price tie on the sale price, so a promotion on an equally priced variant is published
		usort(
			$pricedVariants,
			static function (array $firstPriced, array $secondPriced): int {
				$byPrice = $firstPriced[0]->compare($secondPriced[0]);

				return $byPrice !== 0 ? $byPrice : $firstPriced[1]->compare($secondPriced[1]);
			}
		);

		return $this->variantsByPrice[$productId] = array_column($pricedVariants, 2);
	}

	/**
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function isInStock(Variant $variant): bool
	{
		return $this->variantValue($variant, 'availability') === Availability::InStock->value;
	}

	private function money(?string $decimal): ?Money
	{
		$currency = $this->feed->getCurrency();

		return $decimal === null || ! $currency instanceof Currency ? null : FeedValue::moneyFromDecimal($decimal, $currency);
	}

	/**
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function anyVariantInStock(Product $product): string
	{
		foreach ($product->getVariants() as $variant) {
			if ($this->isInStock($variant)) {
				return Availability::InStock->value;
			}
		}

		return Availability::OutOfStock->value;
	}

	/**
	 * A variant not for sale adds no stock. An untracked variant for sale makes the total unknown, and a
	 * number sent anyway would undercount it.
	 *
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function totalVariantStock(Product $product): ?string
	{
		$quantities = [];
		foreach ($product->getVariants() as $variant) {
			if (! $variant->availableForPurchase) {
				continue;
			}

			$quantity = $this->variantValue($variant, 'inventory_quantity');
			if ($quantity === null) {
				return null;
			}

			$quantities[] = (int) $quantity;
		}

		return $quantities === [] ? null : (string) array_sum($quantities);
	}
}
