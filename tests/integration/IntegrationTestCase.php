<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use Craft;
use craft\queue\Queue;
use fostercommerce\productfeeds\jobs\BuildFeed;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\services\AutoRebuild;
use fostercommerce\productfeeds\services\Builds;
use fostercommerce\productfeeds\services\Feeds;
use PHPUnit\Framework\TestCase;

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
				$this->feeds()->clearPending((int) $feed->id);
			}
		}
	}
}
