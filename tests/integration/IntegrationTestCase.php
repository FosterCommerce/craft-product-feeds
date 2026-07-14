<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use Craft;
use craft\elements\Asset;
use craft\queue\Queue;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\jobs\BuildFeed;
use fostercommerce\productfeeds\models\BuildResult;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\services\AutoRebuild;
use fostercommerce\productfeeds\services\BuildQueue;
use fostercommerce\productfeeds\services\Builds;
use fostercommerce\productfeeds\services\Feeds;
use fostercommerce\productfeeds\sources\FeedSource;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Boots against the Craft install named by `CRAFT_BASE_PATH`, and skips when there is none, so the
 * unit suite still runs on its own.
 *
 * Feeds created here carry the `pfTest` handle prefix. They, and any build a test queued, are torn
 * down afterwards, so the suite can run against a working install without leaving anything behind.
 */
abstract class IntegrationTestCase extends TestCase
{
	protected const HANDLE_PREFIX = 'pfTest';

	/**
	 * @var list<int>
	 */
	private array $preexistingJobIds = [];

	protected function setUp(): void
	{
		parent::setUp();

		if (! defined('CRAFT_BASE_PATH')) {
			$this->markTestSkipped('Set CRAFT_BASE_PATH to a Craft install to run the integration suite.');
		}

		$this->preexistingJobIds = $this->buildJobIds();
		$this->deleteTestFeeds();
	}

	protected function tearDown(): void
	{
		if (defined('CRAFT_BASE_PATH')) {
			$this->releaseQueuedBuilds();
			$this->deleteTestFeeds();
		}

		parent::tearDown();
	}

	protected function plugin(): ProductFeeds
	{
		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();

		return $plugin;
	}

	protected function feeds(): Feeds
	{
		return $this->plugin()->getFeeds();
	}

	protected function builds(): Builds
	{
		return $this->plugin()->getBuilds();
	}

	protected function buildQueue(): BuildQueue
	{
		return $this->plugin()->getBuildQueue();
	}

	protected function primarySiteId(): int
	{
		return (int) Craft::$app->getSites()->getPrimarySite()->id;
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	protected function makeFeed(string $handle, array $attributes = []): Feed
	{
		$feed = new Feed([
			'name' => 'Test ' . $handle,
			'handle' => self::HANDLE_PREFIX . ucfirst($handle),
			'siteId' => $this->primarySiteId(),
			...$attributes,
		]);

		$this->assertTrue(
			$this->feeds()->saveFeed($feed),
			'Feed did not save: ' . json_encode($feed->getErrors())
		);

		// AutoRebuild memoizes the enabled feeds, and the service outlives a test.
		$this->plugin()->set('autoRebuild', AutoRebuild::class);

		return $feed;
	}

	/**
	 * A feed whose mapping is complete enough to publish items. `title` and `link` come off the product;
	 * the rest take defaults, so a test never depends on which custom fields the install happens to have.
	 *
	 * @param array<string, array{source: string, default: string}> $fieldMapping merged over the defaults
	 */
	protected function buildableFeed(string $handle, Platform $platform, array $fieldMapping = []): Feed
	{
		$image = Asset::find()
			->kind(Asset::KIND_IMAGE)
			->one();

		if (! $image instanceof Asset || $image->getUrl() === null) {
			$this->markTestSkipped('This install has no image asset with a public URL.');
		}

		$feed = $this->makeFeed($handle, [
			'platform' => $platform->value,
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
				...$fieldMapping,
			],
		]);

		if (FeedSource::forFeed($feed)->query()->count() === 0) {
			$this->markTestSkipped('This install has no live variants to build a feed from.');
		}

		return $feed;
	}

	/**
	 * Builds the feed, skipping the test where the install itself cannot produce one (no Commerce store
	 * on the site, no source with public URLs).
	 *
	 * @throws Throwable
	 */
	protected function buildOrSkip(Feed $feed): BuildResult
	{
		try {
			$result = $this->builds()->build($feed);
		} catch (FeedBuildException $feedBuildException) {
			$this->markTestSkipped('This install cannot build a feed: ' . $feedBuildException->getMessage());
		}

		// Every caller asserts over the published items, and an empty feed would pass all of those vacuously.
		$this->assertGreaterThan(0, $result->itemCount, 'The catalog produced no items to assert against.');

		return $result;
	}

	/**
	 * The published artifact, inflated. Every feed is written as a single-member gzip whatever its format.
	 */
	protected function publishedArtifact(Feed $feed): string
	{
		$fs = $this->feeds()->getFs();
		$path = $feed->getPath();

		$this->assertTrue($fs->fileExists($path), 'The build published no artifact.');

		$stream = $fs->getFileStream($path);
		stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, [
			'window' => 31,
		]);

		$contents = (string) stream_get_contents($stream);
		fclose($stream);

		return $contents;
	}

	/**
	 * Builds queued since this test started. A job the install already had waiting is not one of ours.
	 */
	protected function queuedBuildCount(): int
	{
		return count(array_diff($this->buildJobIds(), $this->preexistingJobIds));
	}

	/**
	 * @return list<int>
	 */
	private function buildJobIds(): array
	{
		$description = (new BuildFeed())->getDescription();
		$ids = [];

		/** @var list<array{id: int|string, description?: string}> $jobs */
		$jobs = $this->queue()->getJobInfo();

		foreach ($jobs as $job) {
			if (($job['description'] ?? '') === $description) {
				$ids[] = (int) $job['id'];
			}
		}

		return $ids;
	}

	private function releaseQueuedBuilds(): void
	{
		$queue = $this->queue();

		foreach (array_diff($this->buildJobIds(), $this->preexistingJobIds) as $jobId) {
			$queue->release((string) $jobId);
		}
	}

	/**
	 * `Craft::$app->getQueue()` is typed as Yii's queue. Craft's own carries the job inspection this
	 * needs, and is what the app is configured with.
	 */
	private function queue(): Queue
	{
		/** @var Queue $queue */
		$queue = Craft::$app->getQueue();

		return $queue;
	}

	private function deleteTestFeeds(): void
	{
		foreach ($this->feeds()->getAllFeeds() as $feed) {
			if (str_starts_with($feed->handle, self::HANDLE_PREFIX)) {
				$this->feeds()->deleteFeedById((int) $feed->id);
				$this->buildQueue()->clearPending((int) $feed->id);
			}
		}
	}
}
