<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\jobs;

use Craft;
use craft\queue\BaseJob;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use Throwable;
use yii\base\InvalidConfigException;
use yii\queue\RetryableJobInterface;

/**
 * Runs a feed build one batch per job. The first run starts the build, each run after that builds one batch,
 * and the run that builds the last batch publishes the feed.
 *
 * A run that stops partway loses only its own batch: a retry continues from the batches already saved.
 */
class BuildFeed extends BaseJob implements RetryableJobInterface
{
	private const MAX_ATTEMPTS = 3;

	public int $feedId;

	/**
	 * Null until the first run starts the build. Later runs pass it back to find the build's saved batches.
	 */
	public ?string $buildId = null;

	/**
	 * How many times this build has requeued because another build already held the lock or was running.
	 */
	public int $requeues = 0;

	/**
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function execute($queue): void
	{
		$plugin = ProductFeeds::plugin();
		$builds = $plugin->getBuilds();
		$buildQueue = $plugin->getBuildQueue();

		$feed = $plugin->getFeeds()->getFeedById($this->feedId);
		if (! $feed instanceof Feed) {
			// Nothing left to build, and the pending flag has no other build to clear it.
			$buildQueue->clearPending($this->feedId);

			return;
		}

		$mutex = Craft::$app->getMutex();
		$lockName = $buildQueue->buildLockName($this->feedId);
		if (! $mutex->acquire($lockName)) {
			$this->standDown($feed);

			return;
		}

		try {
			$buildId = $this->buildId;
			if ($buildId === null) {
				// Another queued build started before the edits this one was queued for. Come back once it has
				// finished rather than dropping them.
				if ($builds->isQueuedBuildRunning($feed)) {
					$this->standDown($feed);

					return;
				}

				$buildQueue->queueNextBatch($this->feedId, $builds->startQueuedBuild($feed));

				return;
			}

			// A later build replaced this one after the build timeout, and deleted its batches
			if (! $builds->queuedBuildExists($feed, $buildId)) {
				return;
			}

			$state = $builds->buildNextQueuedBatch($feed, $buildId);
			$batchCount = count($state->elementIdBatches);
			$this->setProgress($queue, $batchCount > 0 ? $state->nextBatch / $batchCount : 1);

			if ($state->hasMoreBatches()) {
				$buildQueue->queueNextBatch($this->feedId, $buildId);

				return;
			}

			$builds->finishQueuedBuild($feed, $buildId);
		} catch (Throwable $throwable) {
			$builds->recordQueuedBuildFailure($feed, $this->buildId, $throwable);

			throw $throwable;
		} finally {
			$mutex->release($lockName);
		}
	}

	public function getTtr(): int
	{
		$plugin = ProductFeeds::plugin();

		return $plugin->getSettings()->buildTimeout;
	}

	public function canRetry($attempt, $error): bool
	{
		return ! $error instanceof FeedBuildException && $attempt < self::MAX_ATTEMPTS;
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(ProductFeeds::HANDLE, 'job.buildFeed');
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function standDown(Feed $feed): void
	{
		if (ProductFeeds::plugin()->getBuildQueue()->requeueBuild($this->feedId, $this->requeues, $this->buildId)) {
			return;
		}

		Craft::warning(sprintf(
			$this->buildId === null
				? 'Product feed “%s” is being edited faster than it builds. It rebuilds on its interval instead.'
				: 'Product feed “%s” stopped building partway, because another build kept its lock. It rebuilds on its interval instead.',
			$feed->handle
		), ProductFeeds::HANDLE);
	}
}
