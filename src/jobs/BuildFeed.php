<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\jobs;

use Craft;
use craft\queue\BaseJob;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\ProductFeeds;
use Throwable;
use yii\base\InvalidConfigException;
use yii\queue\RetryableJobInterface;

class BuildFeed extends BaseJob implements RetryableJobInterface
{
	private const MAX_ATTEMPTS = 3;

	public int $feedId;

	/**
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function execute($queue): void
	{
		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();

		$feeds = $plugin->getFeeds();
		$feed = $feeds->getFeedById($this->feedId);
		if ($feed === null) {
			return;
		}

		$mutex = Craft::$app->getMutex();
		$lockName = $feeds->buildLockName($feed);

		// Zero timeout rather than blocking a worker for the length of a full build. The build already
		// running started before this job's edits landed, so the feed is left dirty for it to pick up.
		// clearPending() comes first: that build reads the pending flag before it acts on the dirty one.
		if (! $mutex->acquire($lockName)) {
			$feeds->clearPending($this->feedId);
			$feeds->markBuildDirty($this->feedId);

			return;
		}

		// This build can no longer absorb new edits, so the next one has to queue a build of its own.
		$feeds->clearPending($this->feedId);

		// It reads the catalog as it stands now, so only the edits that land while it runs need the flag.
		$feeds->clearBuildDirty($this->feedId);

		try {
			// Craft masks queue errors from non-admins outside dev mode, so the outcome recorded on the
			// feed row is the only place a failure is readable.
			$plugin->getBuilds()->buildAndRecord(
				$feed,
				function (int $done, int $total) use ($queue): void {
					$this->setProgress($queue, $total > 0 ? $done / $total : 1);
				}
			);
		} finally {
			$mutex->release($lockName);
		}

		$feeds->requestBuildIfDirty($this->feedId);
	}

	public function getTtr(): int
	{
		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();

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
