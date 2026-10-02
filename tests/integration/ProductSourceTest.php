<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\Asset;
use craft\models\Site;
use fostercommerce\productfeeds\enums\Availability;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\enums\Source;
use fostercommerce\productfeeds\helpers\FeedEditVariables;
use fostercommerce\productfeeds\helpers\FeedValue;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\helpers\MappingOptions;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\sources\FeedSource;
use fostercommerce\productfeeds\sources\ProductSource;
use Money\Currency;
use Money\Money;

class ProductSourceTest extends IntegrationTestCase
{
	public function testAProductsFeedReadsProductsAndDerivesOnlyTheirId(): void
	{
		$source = FeedSource::forFeed($this->productFeed(Platform::Google));
		$this->assertInstanceOf(ProductSource::class, $source);
		$this->assertSame(['id'], $source->computedAttributes());

		$product = $source->query()->one();
		if (! $product instanceof Product) {
			$this->markTestSkipped('This install has no live product in a product type with URLs.');
		}

		$this->assertSame((string) $product->id, $source->compute($product, 'id'));
	}

	/**
	 * The calculations are offered only on the attributes they produce a value for.
	 */
	public function testOnlyPriceAndStockRowsOfferTheVariantCalculations(): void
	{
		$feed = $this->productFeed(Platform::Google);
		$options = MappingOptions::forSource(FeedSource::forFeed($feed), $feed->getSpec());

		$this->assertContains(Mapping::build(Mapping::ELEMENT, 'lowestVariantPrice'), array_column($options['price'], 'value'));
		$this->assertContains(Mapping::build(Mapping::ELEMENT, 'anyVariantInStock'), array_column($options['availability'], 'value'));
		$this->assertNotContains(Mapping::build(Mapping::ELEMENT, 'lowestVariantPrice'), array_column($options['title'], 'value'));
	}

	public function testANewFeedMapsPriceAndStockToTheVariantCalculations(): void
	{
		$defaultMapping = FeedSource::forFeed($this->productFeed(Platform::Google))->defaultMapping();

		$this->assertSame(Mapping::build(Mapping::ELEMENT, 'lowestVariantPrice'), $defaultMapping['price']['source']);
		$this->assertSame(Mapping::build(Mapping::ELEMENT, 'lowestVariantSalePrice'), $defaultMapping['sale_price']['source']);
		$this->assertSame(Mapping::build(Mapping::ELEMENT, 'anyVariantInStock'), $defaultMapping['availability']['source']);
		$this->assertSame(Mapping::build(Mapping::ELEMENT, 'totalVariantStock'), $defaultMapping['inventory_quantity']['source']);
	}

	/**
	 * A product's figures are the variant feed's figures for its variants, combined.
	 */
	public function testPriceCalculationsMatchTheVariantFeed(): void
	{
		$feed = $this->productFeed(Platform::Google);
		$product = $this->productWith($feed, static fn (Product $product): bool => count($product->getVariants()) > 1);
		$variantPrices = $this->variantFeedValues($feed, $this->pricedVariants($feed, $product), 'price');

		if ($variantPrices === []) {
			$this->markTestSkipped('This install has no product whose variants have prices.');
		}

		$sortedPrices = $this->sortedPrices($feed, $variantPrices);

		$this->assertSame([$sortedPrices[0]], $this->resolve($feed, $product, 'price', 'lowestVariantPrice'));
		$this->assertSame([end($sortedPrices)], $this->resolve($feed, $product, 'price', 'highestVariantPrice'));
		$this->assertSame(
			$this->variantFeedValues($feed, array_filter([$product->getDefaultVariant()]), 'price'),
			$this->resolve($feed, $product, 'price', 'defaultVariantPrice')
		);
	}

	/**
	 * A product whose lowest-priced variant isn't on promotion has no sale price, whatever its other variants.
	 */
	public function testTheSalePriceComesFromTheLowestPricedVariant(): void
	{
		$feed = $this->productFeed(Platform::Google);
		$product = $this->productWith($feed, static fn (Product $product): bool => count($product->getVariants()) > 0);

		$lowestPricedVariant = null;
		$lowestPrice = null;
		foreach ($this->pricedVariants($feed, $product) as $variant) {
			$price = $this->variantFeedValues($feed, [$variant], 'price')[0] ?? null;
			if ($price !== null && ($lowestPrice === null || $this->money($feed, $price)->lessThan($this->money($feed, $lowestPrice)))) {
				$lowestPrice = $price;
				$lowestPricedVariant = $variant;
			}
		}

		$this->assertSame(
			$lowestPricedVariant === null ? [] : $this->variantFeedValues($feed, [$lowestPricedVariant], 'sale_price'),
			$this->resolve($feed, $product, 'sale_price', 'lowestVariantSalePrice')
		);
	}

	public function testAProductIsInStockWhenAnyVariantIs(): void
	{
		$feed = $this->productFeed(Platform::Google);
		$product = $this->productWith($feed, static fn (Product $product): bool => count($product->getVariants()) > 1);
		$variantAvailability = $this->variantFeedValues($feed, $product->getVariants()->all(), 'availability');

		$this->assertSame(
			[in_array(Availability::InStock->value, $variantAvailability, true) ? Availability::InStock->value : Availability::OutOfStock->value],
			$this->resolve($feed, $product, 'availability', 'anyVariantInStock')
		);
	}

	public function testTotalStockSumsTheVariantsForSale(): void
	{
		$feed = $this->productFeed(Platform::Klaviyo);
		$product = $this->productWith(
			$feed,
			fn (Product $product): bool => $this->untrackedForSaleCount($feed, $product) === 0
				&& $this->forSaleVariants($product) !== []
		);
		$quantities = $this->variantFeedValues($feed, $this->forSaleVariants($product), 'inventory_quantity');

		$this->assertSame([(string) array_sum(array_map(intval(...), $quantities))], $this->resolve($feed, $product, 'inventory_quantity', 'totalVariantStock'));
	}

	/**
	 * A number sent with an untracked variant left out would undercount the stock for sale.
	 */
	public function testTotalStockIsLeftOutWhenAVariantForSaleIsUntracked(): void
	{
		$feed = $this->productFeed(Platform::Klaviyo);
		$product = $this->productWith($feed, fn (Product $product): bool => $this->untrackedForSaleCount($feed, $product) > 0);

		$this->assertSame([], $this->resolve($feed, $product, 'inventory_quantity', 'totalVariantStock'));
	}

	public function testPricesReadEveryVariantWhenNoneIsInStock(): void
	{
		$feed = $this->productFeed(Platform::Google);
		$product = $this->productWith(
			$feed,
			fn (Product $product): bool => count($product->getVariants()) > 0
				&& $this->variantFeedValues($feed, $product->getVariants()->all(), 'availability') === array_fill(0, count($product->getVariants()), Availability::OutOfStock->value)
		);
		$variantPrices = $this->variantFeedValues($feed, $product->getVariants()->all(), 'price');

		if ($variantPrices === []) {
			$this->markTestSkipped('This install has no out-of-stock product whose variants have prices.');
		}

		$sortedPrices = $this->sortedPrices($feed, $variantPrices);

		$this->assertSame([$sortedPrices[0]], $this->resolve($feed, $product, 'price', 'lowestVariantPrice'));
	}

	public function testAProductWithNoEnabledVariantHasNoCalculatedValues(): void
	{
		$feed = $this->productFeed(Platform::Klaviyo);
		$product = $this->productWith($feed, static fn (Product $product): bool => count($product->getVariants()) === 0);

		$this->assertSame([], $this->resolve($feed, $product, 'price', 'lowestVariantPrice'));
		$this->assertSame([], $this->resolve($feed, $product, 'inventory_quantity', 'totalVariantStock'));
		$this->assertSame([Availability::OutOfStock->value], $this->resolve($feed, $product, 'availability', 'anyVariantInStock'));
	}

	/**
	 * Google rejects a sale price that isn't lower than the price, which a default variant's promotion can
	 * reach when the price is another variant's.
	 */
	public function testASalePriceStaysBelowTheCalculatedPrice(): void
	{
		$feed = $this->productFeed(Platform::Google, [
			'price' => [
				'source' => Mapping::build(Mapping::ELEMENT, 'lowestVariantPrice'),
				'default' => '',
			],
			'sale_price' => [
				'source' => Mapping::build(Mapping::ELEMENT, 'defaultVariantSalePrice'),
				'default' => '',
			],
		]);
		$checkedCount = 0;
		foreach ($this->products($feed) as $product) {
			$salePrice = $this->resolve($feed, $product, 'sale_price', 'defaultVariantSalePrice')[0] ?? null;
			if ($salePrice === null) {
				continue;
			}

			++$checkedCount;

			$price = $this->resolve($feed, $product, 'price', 'lowestVariantPrice')[0] ?? null;
			$this->assertNotNull($price);

			$this->assertTrue($this->money($feed, $salePrice)->lessThan($this->money($feed, $price)), sprintf('Product %s', $product->id));
		}

		if ($checkedCount === 0) {
			$this->markTestSkipped('This install has no product whose default variant is on promotion below the lowest price.');
		}
	}

	/**
	 * Price and stock are variant data, so a variant save has to rebuild a products feed.
	 */
	public function testAVariantSaveCountsAsAChangeToItsProduct(): void
	{
		$feed = $this->productFeed(Platform::Google);
		$source = FeedSource::forFeed($feed);
		$product = $this->productWith($feed, static fn (Product $product): bool => count($product->getVariants()) > 0);
		$variant = $product->getVariants()->first();
		$this->assertInstanceOf(Variant::class, $variant);

		$this->assertTrue($source->handles($variant));
		$this->assertTrue($source->contains($variant));
		$this->assertTrue($source->mightContain($variant));
	}

	public function testAKlaviyoProductsFeedPublishesOneItemPerProduct(): void
	{
		$image = Asset::find()
			->kind(Asset::KIND_IMAGE)
			->one();

		if (! $image instanceof Asset || $image->getUrl() === null) {
			$this->markTestSkipped('This install has no image asset with a public URL.');
		}

		$source = FeedSource::forFeed($this->productFeed(Platform::Klaviyo));
		$feed = $this->makeFeed('productsKlaviyo', [
			'source' => Source::Products->value,
			'platform' => Platform::Klaviyo->value,
			'fieldMapping' => [
				...$source->defaultMapping(),
				'description' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => 'A description, so the item is not excluded.',
				],
				'image_link' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => (string) $image->id,
				],
			],
		]);

		$result = $this->buildOrSkip($feed);
		$items = json_decode($this->publishedArtifact($feed), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($items);

		$productIds = array_map(strval(...), $source->query()->ids());
		$publishedIds = array_column($items, '$id');

		$this->assertCount($result->itemCount, $items);
		$this->assertSame([], array_diff($publishedIds, $productIds));

		// A product with no tracked stock is expected, not an error to report
		$this->assertArrayNotHasKey('inventory_quantity', $result->buildDiagnostics->blankByAttribute);

		$feed->lastBuildDiagnostics = $result->buildDiagnostics;
		$feed->lastBuildItemCount = $result->itemCount;
		$site = Craft::$app->getSites()->getSiteById($this->primarySiteId());
		$this->assertInstanceOf(Site::class, $site);
		/** @var list<array<string, mixed>> $mappingRows */
		$mappingRows = FeedEditVariables::forFeed($feed, $site)['mappingRows'];
		$rows = array_column($mappingRows, null, 'name');

		// The uncounted blanks mean a calculation can't claim every item
		$this->assertNull($rows['inventory_quantity']['setOnAllItems']);
		$this->assertNull($rows['inventory_quantity']['blanks']);
	}

	/**
	 * @param array<string, array{source: string, default: string}> $fieldMapping
	 */
	private function productFeed(Platform $platform, array $fieldMapping = []): Feed
	{
		return new Feed([
			'source' => Source::Products->value,
			'platform' => $platform->value,
			'siteId' => $this->primarySiteId(),
			'fieldMapping' => $fieldMapping,
		]);
	}

	/**
	 * Every product in the feed, with its batch loaded.
	 *
	 * @return Product[]
	 */
	private function products(Feed $feed): array
	{
		$source = FeedSource::forFeed($feed);

		/** @var Product[] $products */
		$products = $source->query()->all();
		$source->prepareBatch($products);

		return $products;
	}

	/**
	 * The first product in the feed matching the test's needs, with its batch loaded.
	 *
	 * @param callable(Product): bool $matches
	 */
	private function productWith(Feed $feed, callable $matches): Product
	{
		foreach ($this->products($feed) as $product) {
			if ($matches($product)) {
				return $product;
			}
		}

		$this->markTestSkipped('This install has no product the test can use.');
	}

	/**
	 * @return list<string>
	 */
	private function resolve(Feed $feed, Product $product, string $attribute, string $calculation): array
	{
		$source = FeedSource::forFeed($feed);
		$source->prepareBatch([$product]);

		return $source->resolve(
			$product,
			Mapping::parse(Mapping::build(Mapping::ELEMENT, $calculation)),
			$feed->getSpec()->attributes()[$attribute],
		);
	}

	/**
	 * What a variant feed on the same site and platform publishes for each variant.
	 *
	 * @param Variant[] $variants
	 * @return list<string>
	 */
	private function variantFeedValues(Feed $feed, array $variants, string $attribute): array
	{
		$variantSource = FeedSource::forFeed(new Feed([
			'source' => Source::Variants->value,
			'platform' => $feed->platform,
			'siteId' => $feed->siteId,
		]));
		$variantSource->prepareBatch($variants);

		$values = [];
		foreach ($variants as $variant) {
			$value = $variantSource->compute($variant, $attribute);
			if (is_string($value)) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * @return Variant[]
	 */
	private function forSaleVariants(Product $product): array
	{
		return array_filter($product->getVariants()->all(), static fn (Variant $variant): bool => $variant->availableForPurchase);
	}

	private function untrackedForSaleCount(Feed $feed, Product $product): int
	{
		$forSaleVariants = $this->forSaleVariants($product);

		return count($forSaleVariants) - count($this->variantFeedValues($feed, $forSaleVariants, 'inventory_quantity'));
	}

	/**
	 * The variants the lowest and highest prices read: those a variant feed lists as in stock, or every
	 * variant when none is.
	 *
	 * @return Variant[]
	 */
	private function pricedVariants(Feed $feed, Product $product): array
	{
		$variants = $product->getVariants()->all();
		$inStockVariants = array_filter(
			$variants,
			fn (Variant $variant): bool => $this->variantFeedValues($feed, [$variant], 'availability') === [Availability::InStock->value]
		);

		return $inStockVariants !== [] ? $inStockVariants : $variants;
	}

	/**
	 * @param non-empty-list<string> $prices
	 * @return non-empty-list<string>
	 */
	private function sortedPrices(Feed $feed, array $prices): array
	{
		usort($prices, fn (string $firstPrice, string $secondPrice): int => $this->money($feed, $firstPrice)->compare($this->money($feed, $secondPrice)));

		return $prices;
	}

	private function money(Feed $feed, string $decimal): Money
	{
		$currency = $feed->getCurrency();
		$this->assertInstanceOf(Currency::class, $currency);

		$money = FeedValue::moneyFromDecimal($decimal, $currency);
		$this->assertInstanceOf(Money::class, $money);

		return $money;
	}
}
