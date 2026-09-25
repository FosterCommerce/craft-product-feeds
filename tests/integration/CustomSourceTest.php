<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use craft\base\ElementInterface;
use craft\commerce\elements\Variant;
use craft\elements\Asset;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\UrlHelper;
use fostercommerce\productfeeds\enums\Availability;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\BuildDiagnostics;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use fostercommerce\productfeeds\sources\MissingSource;
use fostercommerce\productfeeds\sources\SuppliedItem;
use fostercommerce\productfeeds\tests\integration\fixtures\TestItemsSource;
use SimpleXMLElement;
use yii\base\Event;

class CustomSourceTest extends IntegrationTestCase
{
	private const GOOGLE_NAMESPACE_URI = 'http://base.google.com/ns/1.0';

	protected function setUp(): void
	{
		parent::setUp();

		Event::on(ProductFeeds::class, ProductFeeds::EVENT_REGISTER_SOURCES, [self::class, 'registerTestSource']);
	}

	protected function tearDown(): void
	{
		TestItemsSource::$itemsFor = null;

		parent::tearDown();

		Event::off(ProductFeeds::class, ProductFeeds::EVENT_REGISTER_SOURCES, [self::class, 'registerTestSource']);
	}

	public static function registerTestSource(RegisterComponentTypesEvent $event): void
	{
		$event->types[] = TestItemsSource::class;
	}

	public function testARegisteredSourceIsOfferedBesideTheBuiltInOnes(): void
	{
		$this->assertContains([
			'value' => TestItemsSource::handle(),
			'label' => TestItemsSource::displayName(),
		], FeedSource::options());
	}

	/**
	 * Each item uses the values its source supplies, and an image asset resolves to its URL.
	 */
	public function testAnElementPublishesOneItemPerSuppliedItem(): void
	{
		$image = $this->imageAsset();
		TestItemsSource::$itemsFor = static fn (ElementInterface $element): array => [
			new SuppliedItem([
				...self::itemFor($element, 'a'),
				'price' => '311',
				'image_link' => $image,
			]),
			new SuppliedItem([
				...self::itemFor($element, 'b'),
				'title' => 'Supplied title',
			]),
		];

		$feed = $this->customSourceFeed('items');
		$rows = $this->builds()->preview($feed, 2);
		$currencyCode = $feed->getCurrency()?->getCode();

		$this->assertCount(2, $rows);
		$this->assertSame($rows[0]['elementId'], $rows[1]['elementId']);
		$this->assertSame('311.00 ' . $currencyCode, $rows[0]['item']['price']);
		$imageLink = $rows[0]['item']['image_link'];
		$this->assertIsString($imageLink);
		$this->assertStringContainsString((string) $image->filename, $imageLink);
		$this->assertSame('Supplied title', $rows[1]['item']['title']);
		$secondId = $rows[1]['item']['id'];
		$this->assertIsString($secondId);
		$this->assertStringEndsWith('-b', $secondId);
	}

	/**
	 * Leave out and count an item whose id an earlier item used, since the platform rejects repeated ids.
	 */
	public function testASecondItemWithTheSameIdIsLeftOut(): void
	{
		$image = $this->imageAsset();
		TestItemsSource::$itemsFor = static fn (ElementInterface $element): array => [
			new SuppliedItem([
				...self::itemFor($element, 'duplicate'),
				'image_link' => $image,
			]),
			new SuppliedItem([
				...self::itemFor($element, 'duplicate'),
				'image_link' => $image,
			]),
		];

		$feed = $this->customSourceFeed('duplicate');
		$result = $this->buildOrSkip($feed);

		$document = new SimpleXMLElement($this->publishedArtifact($feed));
		$publishedIds = [];

		foreach ($document->channel->item as $item) {
			$publishedIds[] = (string) $item->children(self::GOOGLE_NAMESPACE_URI)->id;
		}

		$this->assertSame($publishedIds, array_values(array_unique($publishedIds)));
		$this->assertSame($result->itemCount, $result->buildDiagnostics->skippedByAttribute[BuildDiagnostics::DUPLICATE_ID] ?? null);
		$this->assertSame(BuildDiagnostics::DUPLICATE_ID, $result->buildDiagnostics->sampleSkipped[0]['reason'] ?? null);
	}

	/**
	 * A Twig value can read the element as `object`, the supplied values as `item`, and the source's own variables.
	 */
	public function testATwigValueRendersPerItem(): void
	{
		$image = $this->imageAsset();
		TestItemsSource::$itemsFor = static fn (ElementInterface $element): array => [
			new SuppliedItem(
				[
					...self::itemFor($element, 'twig'),
					'image_link' => $image,
				],
				[
					'finish' => 'Matte',
				],
			),
		];

		$feed = $this->customSourceFeed('twig');
		$feed->fieldMapping['custom_label_0'] = [
			'source' => Mapping::TWIG,
			'default' => '',
			'twig' => '{{ object.id }}|{{ item.id }}|{{ finish }}',
		];
		$rows = $this->builds()->preview($feed, 1);

		$elementId = $rows[0]['elementId'];
		$this->assertSame(sprintf('%d|%d-twig|Matte', $elementId, $elementId), $rows[0]['item']['custom_label_0']);
	}

	/**
	 * A template that fails leaves its attribute blank and is counted, instead of failing the build.
	 */
	public function testABrokenTwigValueLeavesTheAttributeBlank(): void
	{
		$image = $this->imageAsset();
		TestItemsSource::$itemsFor = static fn (ElementInterface $element): array => [
			new SuppliedItem([
				...self::itemFor($element, 'broken'),
				'image_link' => $image,
			]),
		];

		$feed = $this->customSourceFeed('brokenTwig');
		$feed->fieldMapping['custom_label_0'] = [
			'source' => Mapping::TWIG,
			'default' => '',
			'twig' => '{{ object.id',
		];
		$result = $this->buildOrSkip($feed);

		$this->assertGreaterThan(0, $result->itemCount);
		// An item excluded for another reason still rendered its Twig value first.
		$this->assertGreaterThanOrEqual($result->itemCount, $result->buildDiagnostics->twigErrorsByAttribute['custom_label_0'] ?? 0);
		$this->assertArrayHasKey('custom_label_0', $result->buildDiagnostics->sampleTwigErrors);
		$this->assertStringNotContainsString('custom_label_0', $this->publishedArtifact($feed));
	}

	public function testAFeedOnASourceNoModuleRegistersCannotBuild(): void
	{
		$feed = new Feed([
			'source' => 'pfTestMissing',
			'siteId' => $this->primarySiteId(),
		]);

		$this->assertInstanceOf(MissingSource::class, FeedSource::forFeed($feed));
		$this->expectException(FeedBuildException::class);
		$this->expectExceptionMessage('pfTestMissing');

		$this->builds()->build($feed);
	}

	/**
	 * @return array<string, string>
	 */
	private static function itemFor(ElementInterface $element, string $suffix): array
	{
		/** @var Variant $element */
		return [
			'id' => $element->id . '-' . $suffix,
			'item_group_id' => (string) $element->id,
			'title' => (string) $element->title,
			'link' => UrlHelper::siteUrl('pf-test/' . $element->id),
			'price' => (string) $element->price,
		];
	}

	private function customSourceFeed(string $handle): Feed
	{
		return $this->makeFeed($handle, [
			'platform' => Platform::Google->value,
			'source' => TestItemsSource::handle(),
			'fieldMapping' => [
				'description' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => 'A description, so the item is not excluded.',
				],
				'availability' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => Availability::InStock->value,
				],
				'condition' => [
					'source' => Mapping::USE_DEFAULT,
					'default' => 'new',
				],
			],
		]);
	}

	private function imageAsset(): Asset
	{
		$image = Asset::find()->kind(Asset::KIND_IMAGE)->one();

		if (! $image instanceof Asset || $image->getUrl() === null) {
			$this->markTestSkipped('This install has no image asset with a public URL.');
		}

		return $image;
	}
}
