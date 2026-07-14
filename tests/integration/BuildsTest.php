<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

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
	 * A required attribute with no source blanks every item, so the build refuses instead of publishing an
	 * empty feed.
	 */
	public function testABuildRefusesWhileARequiredAttributeIsUnmapped(): void
	{
		$feed = $this->makeFeed('unmapped');
		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$source = FeedSource::forFeed($feed);

		$this->assertContains('description', $this->builds()->unmappedRequiredAttributes($feed, $spec, $source));

		// Not `buildOrSkip()`: the refusal is what this test is asserting.
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
		$feed = $this->googleFeed('gzip');
		$result = $this->buildOrSkip($feed);

		$this->assertGreaterThan(0, $result->bytes);
		$this->assertNotNull($result->bytesUncompressed);

		$document = new SimpleXMLElement($this->publishedArtifact($feed));

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
		$feed = $this->googleFeed('required');
		$this->buildOrSkip($feed);

		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$document = new SimpleXMLElement($this->publishedArtifact($feed));

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
		$feed = $this->googleFeed('price');
		$this->buildOrSkip($feed);

		$currencyCode = $feed->getCurrency()?->getCode();
		$this->assertNotNull($currencyCode);

		$document = new SimpleXMLElement($this->publishedArtifact($feed));

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
		$feed = $this->googleFeed('ids');
		$this->buildOrSkip($feed);

		$document = new SimpleXMLElement($this->publishedArtifact($feed));

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
		$feed = $this->googleFeed('identifier');
		$this->buildOrSkip($feed);

		$document = new SimpleXMLElement($this->publishedArtifact($feed));

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
		$feed = $this->googleFeed('preview');
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
	 * A Google feed with the two attributes a shopping platform needs beyond the shared defaults.
	 */
	private function googleFeed(string $handle): Feed
	{
		return $this->buildableFeed($handle, Platform::Google, [
			'condition' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => 'new',
			],
			'availability' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => Availability::InStock->value,
			],
		]);
	}
}
