<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue;
use DateTime;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\jobs\BuildFeed;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\Settings;
use fostercommerce\productfeeds\ProductFeeds;
use yii\base\InvalidConfigException;

/**
 * Decides when a feed builds, and makes sure only one build of it runs at a time.
 *
 * A pending flag collapses a burst of edits into one build; a mutex keeps two builds off the same file,
 * and a build that finds it held requeues rather than coordinating with the holder.
 */
class BuildQueue extends Component
{
	/**
	 * Five minutes. A job that has not started by then is treated as lost, so a new build can queue.
	 */
	private const PENDING_TTL = 300;

	/**
	 * How long a build that lost the mutex waits before trying again, by which time the build that holds it
	 * has usually finished.
	 */
	private const REQUEUE_DELAY = 60;

	/**
	 * Bounds the case where a feed is edited faster than it builds. Giving up leaves the feed to its
	 * scheduled interval, which is within the freshness budget; re-queueing forever is not.
	 */
	private const MAX_REQUEUES = 5;

	/**
	 * @throws InvalidConfigException
	 */
	public function enqueueDueBuilds(): int
	{
		$queued = 0;

		foreach (ProductFeeds::plugin()->getFeeds()->getEnabledFeeds() as $feed) {
			if (! $this->isDue($feed)) {
				continue;
			}

			if ($this->requestBuild((int) $feed->id)) {
				$queued++;
			}
		}

		return $queued;
	}

	public function isDue(Feed $feed): bool
	{
		$now = DateTimeHelper::currentUTCDateTime()->getTimestamp();

		if ($feed->getLastBuildStatus() === BuildStatus::Building) {
			// A worker killed mid-build never clears the status, so without a timeout the feed never rebuilds.
			$timeout = $this->settings()->buildTimeout;
			$startedAt = $feed->lastBuildStartedAt;

			return $startedAt instanceof DateTime && $now - $startedAt->getTimestamp() > $timeout;
		}

		if (! $feed->lastBuildFinishedAt instanceof DateTime) {
			return true;
		}

		return $now - $feed->lastBuildFinishedAt->getTimestamp() >= $this->settings()->buildInterval;
	}

	/**
	 * Queues a build now, unless one is already queued and waiting to start.
	 *
	 * @return bool false when a queued build will already pick the change up
	 */
	public function requestBuild(int $feedId): bool
	{
		$cache = Craft::$app->getCache();

		if ($cache !== null && ! $cache->add($this->pendingKey($feedId), true, self::PENDING_TTL)) {
			return false;
		}

		Queue::push(new BuildFeed([
			'feedId' => $feedId,
		]));

		return true;
	}

	public function isBuildPending(int $feedId): bool
	{
		$cache = Craft::$app->getCache();

		return $cache !== null && $cache->get($this->pendingKey($feedId)) !== false;
	}

	/**
	 * Every build that stops absorbing edits must clear it, or `requestBuild()` no-ops until the flag
	 * expires.
	 */
	public function clearPending(int $feedId): void
	{
		Craft::$app->getCache()?->delete($this->pendingKey($feedId));
	}

	/**
	 * Keyed by ID: a handle is only unique within its site.
	 */
	public function buildLockName(int $feedId): string
	{
		return sprintf('product-feeds:%d', $feedId);
	}

	/**
	 * Queues a build again after a delay, for the one that found another build already holding the lock.
	 * The feed stays pending in the meantime, so edits arriving during the wait ride on this build.
	 *
	 * @return bool false once the feed has stood down too many times, leaving it to its interval
	 */
	public function requeueBuild(int $feedId, int $requeues): bool
	{
		if ($requeues >= self::MAX_REQUEUES) {
			// Nothing is queued behind the flag now, so leaving it set would block the interval rebuild that
			// this stand-down is falling back on.
			$this->clearPending($feedId);

			return false;
		}

		Craft::$app->getCache()?->set($this->pendingKey($feedId), true, self::PENDING_TTL);

		Queue::push(new BuildFeed([
			'feedId' => $feedId,
			'requeues' => $requeues + 1,
		]), null, self::REQUEUE_DELAY);

		return true;
	}

	private function pendingKey(int $feedId): string
	{
		return sprintf('product-feeds:pending:%d', $feedId);
	}

	private function settings(): Settings
	{
		return ProductFeeds::plugin()->getSettings();
	}
}
