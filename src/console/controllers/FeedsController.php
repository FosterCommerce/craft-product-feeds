<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\console\controllers;

use Craft;
use craft\console\Controller;
use fostercommerce\productfeeds\ProductFeeds;
use Throwable;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;

class FeedsController extends Controller
{
	/**
	 * Handle of a single feed to build. Omit to build every enabled feed that is due.
	 */
	public ?string $feed = null;

	/**
	 * Build every enabled feed now, whether or not it is due.
	 */
	public bool $all = false;

	/**
	 * Build in this process rather than queueing. Bypasses the queue's error reporting: a failed build
	 * writes its message to stderr and exits non-zero. Craft Cloud kills console commands at 15 minutes.
	 */
	public bool $inline = false;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'feed', 'all', 'inline'];
	}

	/**
	 * Builds feeds, or queues them. Run on a schedule: with no options it queues only the feeds whose
	 * last build is older than the build interval, so it is safe to run more often than that.
	 *
	 * @throws InvalidConfigException
	 */
	public function actionBuild(): int
	{
		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();

		$feeds = $plugin->getFeeds();

		if ($this->feed === null && ! $this->all) {
			$this->stdout(sprintf("Queued %d due feed(s).\n", $feeds->enqueueDueBuilds()));

			return ExitCode::OK;
		}

		$targets = $this->feed === null
			? $feeds->getEnabledFeeds()
			: array_values(array_filter($feeds->getAllFeeds(), fn ($feed): bool => $feed->handle === $this->feed));

		if ($targets === []) {
			$this->stderr("No matching feeds.\n");

			return ExitCode::DATAERR;
		}

		$mutex = Craft::$app->getMutex();

		foreach ($targets as $target) {
			if (! $this->inline) {
				$feeds->requestBuild((int) $target->id);
				$this->stdout(sprintf("Queued “%s”.\n", $target->name));
				continue;
			}

			$lockName = $feeds->buildLockName($target);
			if (! $mutex->acquire($lockName)) {
				$this->stderr(sprintf("Already building “%s”.\n", $target->name));

				return ExitCode::TEMPFAIL;
			}

			try {
				$result = $plugin->getBuilds()->buildAndRecord($target);
				$this->stdout(sprintf(
					"Built “%s”: %d items, %d skipped.\n",
					$target->name,
					$result->itemCount,
					$result->skippedCount()
				));
			} catch (Throwable $buildException) {
				$this->stderr(sprintf("Failed “%s”: %s\n", $target->name, $buildException->getMessage()));

				return ExitCode::UNSPECIFIED_ERROR;
			} finally {
				$mutex->release($lockName);
			}

			$feeds->requestBuildIfDirty((int) $target->id);
		}

		return ExitCode::OK;
	}
}
