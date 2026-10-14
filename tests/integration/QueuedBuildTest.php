<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use Craft;
use craft\models\FsListing;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\jobs\BuildFeed;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\QueuedBuildState;
use fostercommerce\productfeeds\sources\FeedSource;
use RuntimeException;
use Throwable;

/**
 * A queued build runs one batch per queue job. These run its steps in one process, the way the queue would
 * run them in order.
 */
class QueuedBuildTest extends IntegrationTestCase
{
	private int $savedBatchSize;

	protected function setUp(): void
	{
		parent::setUp();

		$this->savedBatchSize = $this->plugin()->getSettings()->batchSize;
	}

	protected function tearDown(): void
	{
		if (defined('CRAFT_BASE_PATH')) {
			$this->plugin()->getSettings()->batchSize = $this->savedBatchSize;
		}

		parent::tearDown();
	}

	/**
	 * Splitting the build across queue runs must not change what it publishes.
	 *
	 * @throws Throwable
	 */
	public function testAQueuedBuildPublishesTheSameFeedAsABuildInOneProcess(): void
	{
		$feed = $this->klaviyoFeed('queuedSame');
		$inOneProcess = $this->buildOrSkip($feed);
		$expectedItems = $this->publishedItems($feed);

		$this->useThreeBatches($feed);
		$buildId = $this->builds()->startQueuedBuild($feed);
		$batchesBuilt = $this->buildEveryBatch($feed, $buildId);
		$queued = $this->builds()->finishQueuedBuild($feed, $buildId);

		$this->assertGreaterThan(1, $batchesBuilt, 'The catalog must span several batches for this test to mean anything.');
		$this->assertSame($inOneProcess->itemCount, $queued->itemCount);
		$this->assertSame($expectedItems, $this->publishedItems($feed));
		$this->assertSame(
			$inOneProcess->buildDiagnostics->skippedCount(),
			$queued->buildDiagnostics->skippedCount(),
			'The diagnostics must add up across the batches.'
		);
	}

	/**
	 * A queue run that is retried after its batch was saved moves on, rather than building the batch again
	 * and excluding its items as duplicates.
	 *
	 * @throws Throwable
	 */
	public function testARetriedRunDoesNotBuildABatchTwice(): void
	{
		$feed = $this->klaviyoFeed('queuedRetry');
		$this->useThreeBatches($feed);

		$buildId = $this->builds()->startQueuedBuild($feed);
		$this->buildEveryBatch($feed, $buildId);
		$state = $this->builds()->buildNextQueuedBatch($feed, $buildId);

		$this->assertFalse($state->hasMoreBatches());

		$result = $this->builds()->finishQueuedBuild($feed, $buildId);
		$this->assertSame(0, $result->buildDiagnostics->skippedByAttribute['duplicateId'] ?? 0);
	}

	/**
	 * A finished build deletes its saved batches, and the feed reads as built.
	 *
	 * @throws Throwable
	 */
	public function testAFinishedBuildIsRecordedAndNoLongerRunning(): void
	{
		$feed = $this->klaviyoFeed('queuedFinish');
		$this->useThreeBatches($feed);

		$buildId = $this->builds()->startQueuedBuild($feed);
		$this->assertTrue($this->builds()->isQueuedBuildRunning($feed), 'A started build must make new builds stand down.');

		$this->buildEveryBatch($feed, $buildId);
		$this->builds()->finishQueuedBuild($feed, $buildId);

		$this->assertFalse($this->builds()->isQueuedBuildRunning($feed));
		$this->assertFalse($this->feeds()->getFs()->directoryExists($feed->getQueuedBuildsPath() . '/' . $buildId));

		$saved = $this->feeds()->getFeedById((int) $feed->id);
		$this->assertInstanceOf(Feed::class, $saved);
		$this->assertSame(BuildStatus::Ok, $saved->getLastBuildStatus());
		$this->assertGreaterThan(0, (int) $saved->lastBuildBytes, 'The feed route 404s until the row carries the size.');
	}

	/**
	 * A build a worker stopped partway must not hold new builds off forever.
	 *
	 * @throws Throwable
	 */
	public function testABuildOlderThanTheTimeoutNoLongerCountsAsRunning(): void
	{
		$feed = $this->klaviyoFeed('queuedExpired');
		$fs = $this->feeds()->getFs();
		$expiredDirectory = $feed->getQueuedBuildsPath() . '/1-expired';
		$fs->write($expiredDirectory . '/state.json', (new QueuedBuildState([]))->toJson());

		$this->assertFalse($this->builds()->isQueuedBuildRunning($feed));

		$buildId = $this->builds()->startQueuedBuild($feed);

		$this->assertFalse($fs->directoryExists($expiredDirectory), 'Starting a build deletes the expired one.');
		$this->assertTrue($fs->directoryExists($feed->getQueuedBuildsPath() . '/' . $buildId));
	}

	/**
	 * A configuration error fails every retry, so the build's batches are deleted rather than left holding
	 * new builds off until the timeout.
	 *
	 * @throws Throwable
	 */
	public function testAConfigurationErrorDeletesTheBuildsBatches(): void
	{
		$feed = $this->klaviyoFeed('queuedConfigError');
		$buildId = $this->builds()->startQueuedBuild($feed);

		$this->builds()->recordQueuedBuildFailure($feed, $buildId, new FeedBuildException('A permanent error.'));

		$this->assertFalse($this->builds()->isQueuedBuildRunning($feed));

		$saved = $this->feeds()->getFeedById((int) $feed->id);
		$this->assertInstanceOf(Feed::class, $saved);
		$this->assertSame(BuildStatus::Failed, $saved->getLastBuildStatus());
		$this->assertSame('A permanent error.', $saved->lastBuildError);
	}

	/**
	 * A transient error is retried, and the retry needs the batches built so far.
	 *
	 * @throws Throwable
	 */
	public function testATransientErrorKeepsTheBuildsBatchesForTheRetry(): void
	{
		$feed = $this->klaviyoFeed('queuedTransient');
		$buildId = $this->builds()->startQueuedBuild($feed);

		$this->builds()->recordQueuedBuildFailure($feed, $buildId, new RuntimeException('The disk is full.'));

		$this->assertTrue($this->builds()->isQueuedBuildRunning($feed));
	}

	/**
	 * A new feed starts with an unmapped description, so its first queue run must fail with the reason
	 * rather than save batches that can never publish.
	 *
	 * @throws Throwable
	 */
	public function testABuildThatCannotPublishFailsBeforeSavingBatches(): void
	{
		$feed = $this->makeFeed('queuedUnmapped', [
			'platform' => Platform::Klaviyo->value,
		]);

		try {
			$this->builds()->startQueuedBuild($feed);
			$this->fail('A feed with an unmapped required attribute must not start building.');
		} catch (FeedBuildException) {
			$this->assertFalse($this->builds()->isQueuedBuildRunning($feed));
		}
	}

	/**
	 * A run that saved its batch but died before saving the state is retried with the old state. The retry
	 * must build the batch again without marking its own items as duplicates.
	 *
	 * @throws Throwable
	 */
	public function testARunRetriedBeforeItsStateWasSavedBuildsTheSameFeed(): void
	{
		$feed = $this->klaviyoFeed('queuedMidRetry');
		$this->buildOrSkip($feed);
		$expectedItems = $this->publishedItems($feed);

		$this->useThreeBatches($feed);
		$fs = $this->feeds()->getFs();
		$buildId = $this->builds()->startQueuedBuild($feed);
		$statePath = $feed->getQueuedBuildsPath() . '/' . $buildId . '/state.json';

		$stateBeforeBatch = $fs->read($statePath);
		$this->builds()->buildNextQueuedBatch($feed, $buildId);
		$fs->write($statePath, $stateBeforeBatch);

		$this->buildEveryBatch($feed, $buildId);
		$result = $this->builds()->finishQueuedBuild($feed, $buildId);

		$this->assertSame(0, $result->buildDiagnostics->skippedByAttribute['duplicateId'] ?? 0);
		$this->assertSame($expectedItems, $this->publishedItems($feed));
	}

	/**
	 * A feed that fails to start must not stay pending, or the next Build click reports a build that was
	 * never queued.
	 *
	 * @throws Throwable
	 */
	public function testABuildThatFailsToStartClearsThePendingFlag(): void
	{
		$feed = $this->makeFeed('queuedPendingCleared', [
			'platform' => Platform::Klaviyo->value,
		]);
		$feedId = (int) $feed->id;

		$this->assertTrue($this->buildQueue()->requestBuild($feedId));

		try {
			$this->builds()->startQueuedBuild($feed);
			$this->fail('A feed with an unmapped required attribute must not start building.');
		} catch (FeedBuildException) {
			$this->assertFalse($this->buildQueue()->isBuildPending($feedId));
		}
	}

	/**
	 * A later build deletes a build older than the timeout. The old build's next job must end quietly,
	 * rather than recording a failure over the build that replaced it.
	 *
	 * @throws Throwable
	 */
	public function testAJobForADeletedBuildEndsWithoutRecordingAFailure(): void
	{
		$feed = $this->klaviyoFeed('queuedSuperseded');

		(new BuildFeed([
			'feedId' => (int) $feed->id,
			'buildId' => '1-deleted',
		]))->execute(Craft::$app->getQueue());

		$saved = $this->feeds()->getFeedById((int) $feed->id);
		$this->assertInstanceOf(Feed::class, $saved);
		$this->assertNotSame(BuildStatus::Failed, $saved->getLastBuildStatus());
	}

	/**
	 * A new build that finds a queued build of the feed running comes back later instead of starting a
	 * second one.
	 *
	 * @throws Throwable
	 */
	public function testANewBuildStandsDownWhileAQueuedBuildIsRunning(): void
	{
		$feed = $this->klaviyoFeed('queuedStandDown');
		$runningBuildId = $this->builds()->startQueuedBuild($feed);
		$queuedBefore = $this->queuedBuildCount();

		(new BuildFeed([
			'feedId' => (int) $feed->id,
		]))->execute(Craft::$app->getQueue());

		$this->assertSame($queuedBefore + 1, $this->queuedBuildCount(), 'The new build must queue itself again.');
		$this->assertSame(
			[$runningBuildId],
			$this->queuedBuildDirectories($feed),
			'The new build must not start a second build of the feed.'
		);
	}

	/**
	 * @return list<string>
	 */
	private function queuedBuildDirectories(Feed $feed): array
	{
		$directories = [];

		/** @var FsListing $listing */
		foreach ($this->feeds()->getFs()->getFileList($feed->getQueuedBuildsPath(), false) as $listing) {
			if ($listing->getIsDir()) {
				$directories[] = $listing->getBasename();
			}
		}

		return $directories;
	}

	/**
	 * Sizes the batches so the feed's catalog takes three of them.
	 */
	private function useThreeBatches(Feed $feed): void
	{
		$total = (int) FeedSource::forFeed($feed)->query()->count();
		$this->plugin()->getSettings()->batchSize = max(1, (int) ceil($total / 3));
	}

	/**
	 * @return int how many batches it built
	 * @throws Throwable
	 */
	private function buildEveryBatch(Feed $feed, string $buildId): int
	{
		$built = 0;

		do {
			$state = $this->builds()->buildNextQueuedBatch($feed, $buildId);
			$built++;
		} while ($state->hasMoreBatches());

		return $built;
	}
}
