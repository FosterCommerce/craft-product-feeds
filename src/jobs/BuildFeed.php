<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\jobs;

use Craft;
use craft\queue\BaseJob;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\models\BuildResult;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use Throwable;
use yii\base\InvalidConfigException;
use yii\queue\RetryableJobInterface;

class BuildFeed extends BaseJob implements RetryableJobInterface
{
	private const MAX_ATTEMPTS = 3;

	public int $feedId;

	/**
	 * How many times this build has requeued because another build already held the lock.
	 */
	public int $requeues = 0;

	/**
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function execute($queue): void
	{
		$plugin = ProductFeeds::plugin();

		$feed = $plugin->getFeeds()->getFeedById($this->feedId);
		if (! $feed instanceof Feed) {
			// Nothing left to build, and the pending flag has no other build to clear it.
			$plugin->getBuildQueue()->clearPending($this->feedId);

			return;
		}

		$result = $plugin->getBuilds()->buildUnderLock(
			$feed,
			function (int $done, int $total) use ($queue): void {
				$this->setProgress($queue, $total > 0 ? $done / $total : 1);
			}
		);

		// Another build was already running, and it started before the edits this one was queued for. Come
		// back once it has finished rather than dropping them.
		if (! $result instanceof BuildResult && ! $plugin->getBuildQueue()->requeueBuild($this->feedId, $this->requeues)) {
			Craft::warning(sprintf(
				'Product feed “%s” is being edited faster than it builds. It rebuilds on its interval instead.',
				$feed->handle
			), ProductFeeds::HANDLE);
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
}
