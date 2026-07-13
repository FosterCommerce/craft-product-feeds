<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use craft\elements\Asset;
use fostercommerce\productfeeds\enums\Availability;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\sources\FeedSource;
use SimpleXMLElement;

class BuildsTest extends IntegrationTestCase
{
	private const GOOGLE_NAMESPACE_URI = 'http://base.google.com/ns/1.0';

	/**
	 * A missing `description` would blank every item, so the build refuses rather than publishing an
	 * empty feed the platform would accept.
	 */
	public function testABuildRefusesWhileARequiredAttributeIsUnmapped(): void
	{
		$feed = $this->makeFeed('unmapped');
		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$source = FeedSource::forFeed($feed);

		$this->assertContains('description', $this->builds()->unmappedRequiredAttributes($feed, $spec, $source));

		$this->expectException(FeedBuildException::class);
		$this->builds()->build($feed);
	}

	/**
	 * "Use default value" with a blank default is not a mapping: it would produce nothing on every item.
	 */
	public function testABlankDefaultDoesNotCountAsMapped(): void
	{
		$feed = $this->makeFeed('blankDefault', [
			'fieldMapping' => [
				'description' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => '',
				],
			],
		]);

		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$source = FeedSource::forFeed($feed);

		$this->assertContains('description', $this->builds()->unmappedRequiredAttributes($feed, $spec, $source));
	}

	public function testABuiltFeedIsGzippedRssCarryingGooglesNamespace(): void
	{
		$feed = $this->buildableFeed('gzip');
		$result = $this->builds()->build($feed);

		$this->assertGreaterThan(0, $result->itemCount, 'The catalog produced no items to assert against.');
		$this->assertGreaterThan(0, $result->bytes);
		$this->assertNotNull($result->bytesUncompressed);

		$document = new SimpleXMLElement($this->publishedXml($feed));

		$this->assertSame('rss', $document->getName());
		$this->assertSame(self::GOOGLE_NAMESPACE_URI, $document->getDocNamespaces()['g'] ?? null);
		$this->assertCount($result->itemCount, $document->channel->item);
	}

	/**
	 * Every item the feed publishes carries the seven attributes every platform requires. An item that
	 * cannot is excluded and counted instead.
	 */
	public function testEveryPublishedItemCarriesTheRequiredAttributes(): void
	{
		$feed = $this->buildableFeed('required');
		$result = $this->builds()->build($feed);

		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$document = new SimpleXMLElement($this->publishedXml($feed));

		$this->assertGreaterThan(0, $result->itemCount);

		foreach ($document->channel->item as $item) {
			$google = $item->children(self::GOOGLE_NAMESPACE_URI);

			foreach ($spec->requiredAttributes() as $attribute) {
				$this->assertNotSame(
					'',
					(string) $google->{$attribute},
					sprintf('A published item is missing the required “%s”.', $attribute)
				);
			}
		}
	}

	/**
	 * Prices come from Commerce as floats and are published through MoneyPHP, so the document carries a
	 * decimal and the store's currency code, never a float artefact.
	 */
	public function testPricesArePublishedAsADecimalAndACurrencyCode(): void
	{
		$feed = $this->buildableFeed('price');
		$this->builds()->build($feed);

		$currencyCode = $feed->getCurrency()?->getCode();
		$this->assertNotNull($currencyCode);

		$document = new SimpleXMLElement($this->publishedXml($feed));

		foreach ($document->channel->item as $item) {
			$price = (string) $item->children(self::GOOGLE_NAMESPACE_URI)->price;

			$this->assertMatchesRegularExpression('/^\d+\.\d{2} ' . $currencyCode . '$/', $price);
		}
	}

	/**
	 * The `id` of a variant feed is the SKU, and `item_group_id` groups a product's variants together.
	 */
	public function testAVariantFeedIdentifiesItemsBySkuAndGroupsThemByProduct(): void
	{
		$feed = $this->buildableFeed('ids');
		$this->builds()->build($feed);

		$document = new SimpleXMLElement($this->publishedXml($feed));

		foreach ($document->channel->item as $item) {
			$google = $item->children(self::GOOGLE_NAMESPACE_URI);

			$this->assertNotSame('', (string) $google->id);
			$this->assertMatchesRegularExpression('/^\d+$/', (string) $google->item_group_id);
		}
	}

	/**
	 * Google's answer for a product with no identifiers. Derived, so no mapping can set it.
	 */
	public function testIdentifierExistsIsSentWhenAnItemHasNoBrandGtinOrMpn(): void
	{
		$feed = $this->buildableFeed('identifier');
		$this->builds()->build($feed);

		$document = new SimpleXMLElement($this->publishedXml($feed));

		foreach ($document->channel->item as $item) {
			$google = $item->children(self::GOOGLE_NAMESPACE_URI);

			$hasIdentifier = (string) $google->brand !== ''
				|| (string) $google->gtin !== ''
				|| (string) $google->mpn !== '';

			$this->assertSame(
				$hasIdentifier ? '' : 'no',
				(string) $google->identifier_exists
			);
		}
	}

	/**
	 * The preview reads the same code path as a build, and must touch neither the filesystem nor the
	 * queue.
	 */
	public function testPreviewReturnsTheItemsABuildWouldPublish(): void
	{
		$feed = $this->buildableFeed('preview');
		$rows = $this->builds()->preview($feed, 3);

		$this->assertNotEmpty($rows);
		$this->assertLessThanOrEqual(3, count($rows));

		foreach ($rows as $row) {
			$this->assertArrayHasKey('elementId', $row);
			$this->assertArrayHasKey('item', $row);
			$this->assertArrayHasKey('missing', $row);

			if ($row['missing'] === null) {
				$this->assertNotSame('', $row['item']['title'] ?? '');
			}
		}
	}

	/**
	 * A variant feed derives price and availability from Commerce, so those rows never reach the mapping
	 * screen.
	 */
	public function testAVariantFeedDerivesItsCommerceAttributes(): void
	{
		$feed = $this->makeFeed('computed');
		$computed = FeedSource::forFeed($feed)->computedAttributes();

		foreach (['id', 'item_group_id', 'price', 'sale_price', 'availability'] as $attribute) {
			$this->assertContains($attribute, $computed);
		}
	}

	/**
	 * A feed whose mapping is complete enough to publish items: `title` and `link` come off the product,
	 * and the two remaining required attributes take defaults, so the test does not depend on which
	 * custom fields the install happens to have.
	 */
	private function buildableFeed(string $handle): Feed
	{
		$image = Asset::find()
			->kind(Asset::KIND_IMAGE)
			->one();

		if (! $image instanceof Asset || $image->getUrl() === null) {
			$this->markTestSkipped('This install has no image asset with a public URL.');
		}

		$feed = $this->makeFeed($handle, [
			'platform' => Platform::Google->value,
			'fieldMapping' => [
				'title' => [
					'source' => Mapping::build(Mapping::ELEMENT, 'product.title'),
					'default' => '',
				],
				'link' => [
					'source' => Mapping::build(Mapping::ELEMENT, 'product.url'),
					'default' => '',
				],
				'description' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => 'A description, so the item is not excluded.',
				],
				'image_link' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => (string) $image->id,
				],
				'condition' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => 'new',
				],
				'availability' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => Availability::InStock->value,
				],
			],
		]);

		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$source = FeedSource::forFeed($feed);

		try {
			$this->builds()->assertBuildable($feed, $spec, $source);
		} catch (FeedBuildException $feedBuildException) {
			$this->markTestSkipped('This install cannot build a variant feed: ' . $feedBuildException->getMessage());
		}

		if ($source->query()->count() === 0) {
			$this->markTestSkipped('This install has no live variants to build a feed from.');
		}

		return $feed;
	}

	private function publishedXml(Feed $feed): string
	{
		$fs = $this->feeds()->getFs();
		$path = $feed->getPath();

		$this->assertTrue($fs->fileExists($path), 'The build published no artifact.');

		$stream = $fs->getFileStream($path);
		stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, [
			'window' => 31,
		]);

		$xml = (string) stream_get_contents($stream);
		fclose($stream);

		return $xml;
	}
}
