<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\console\controllers;

use craft\console\Controller;
use fostercommerce\productfeeds\models\BuildResult;
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
		$plugin = ProductFeeds::plugin();

		$feedsService = $plugin->getFeeds();
		$buildQueue = $plugin->getBuildQueue();

		if ($this->feed === null && ! $this->all) {
			$this->stdout(sprintf("Queued %d due feed(s).\n", $buildQueue->enqueueDueBuilds()));

			return ExitCode::OK;
		}

		$feeds = $this->feed === null
			? $feedsService->getEnabledFeeds()
			: $feedsService->getFeedsByHandle($this->feed);

		if ($feeds === []) {
			$this->stderr("No matching feeds.\n");

			return ExitCode::DATAERR;
		}

		// One feed failing must not take the rest of the run down with it. The command exits on the worst
		// outcome across all feeds.
		$exitCode = ExitCode::OK;

		foreach ($feeds as $feed) {
			if (! $this->inline) {
				$buildQueue->requestBuild((int) $feed->id);
				$this->stdout(sprintf("Queued “%s”.\n", $feed->name));
				continue;
			}

			try {
				$result = $plugin->getBuilds()->buildUnderLock($feed);
			} catch (Throwable $buildException) {
				$this->stderr(sprintf("Failed “%s”: %s\n", $feed->name, $buildException->getMessage()));
				$exitCode = ExitCode::UNSPECIFIED_ERROR;
				continue;
			}

			if (! $result instanceof BuildResult) {
				$this->stderr(sprintf("Already building “%s”.\n", $feed->name));

				if ($exitCode === ExitCode::OK) {
					$exitCode = ExitCode::TEMPFAIL;
				}

				continue;
			}

			$this->stdout(sprintf(
				"Built “%s”: %d items, %d skipped.\n",
				$feed->name,
				$result->itemCount,
				$result->buildDiagnostics->skippedCount()
			));
		}

		return $exitCode;
	}
}
