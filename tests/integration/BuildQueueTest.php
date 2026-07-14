<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use Craft;
use DateTime;
use fostercommerce\productfeeds\enums\BuildStatus;

class BuildQueueTest extends IntegrationTestCase
{
	/**
	 * Saving a product fires an element save per variant. Without the pending flag each one would queue
	 * its own build of the same feed.
	 */
	public function testRequestBuildCollapsesABurstIntoOneQueuedBuild(): void
	{
		$feed = $this->makeFeed('pending');
		$feedId = (int) $feed->id;

		$this->assertFalse($this->buildQueue()->isBuildPending($feedId));

		$this->buildQueue()->requestBuild($feedId);
		$this->assertTrue($this->buildQueue()->isBuildPending($feedId));

		$queuedBefore = $this->queuedBuildCount();
		$this->buildQueue()->requestBuild($feedId);
		$this->buildQueue()->requestBuild($feedId);

		$this->assertSame($queuedBefore, $this->queuedBuildCount(), 'A pending build should absorb further requests.');
	}

	/**
	 * The job clears the flag as soon as it stops absorbing edits. If it did not, `requestBuild()` would
	 * no-op until the flag expired and those edits would wait for the next interval build.
	 */
	public function testClearPendingLetsTheNextEditQueueAgain(): void
	{
		$feed = $this->makeFeed('clearPending');
		$feedId = (int) $feed->id;

		$this->buildQueue()->requestBuild($feedId);
		$this->buildQueue()->clearPending($feedId);

		$this->assertFalse($this->buildQueue()->isBuildPending($feedId));

		$queuedBefore = $this->queuedBuildCount();
		$this->buildQueue()->requestBuild($feedId);

		$this->assertSame($queuedBefore + 1, $this->queuedBuildCount());
	}

	/**
	 * A build that cannot take the lock stands down. The edits it was queued for are not in the build that
	 * is already running, so it has to come back for them.
	 */
	public function testABuildThatCannotTakeTheLockStandsDown(): void
	{
		$feed = $this->makeFeed('locked');
		$feedId = (int) $feed->id;

		$lockName = $this->buildQueue()->buildLockName($feedId);
		$mutex = Craft::$app->getMutex();

		$this->assertTrue($mutex->acquire($lockName), 'Could not take the lock the test needs to hold.');

		try {
			$this->assertNull($this->builds()->buildUnderLock($feed), 'A second build must stand down.');
		} finally {
			$mutex->release($lockName);
		}
	}

	/**
	 * The edits that lost the race still rebuild: the build that stood down queues itself again.
	 */
	public function testAStoodDownBuildComesBackAndStaysPendingWhileItWaits(): void
	{
		$feed = $this->makeFeed('requeue');
		$feedId = (int) $feed->id;

		$queuedBefore = $this->queuedBuildCount();

		$this->assertTrue($this->buildQueue()->requeueBuild($feedId, 0));

		$this->assertSame($queuedBefore + 1, $this->queuedBuildCount());
		$this->assertTrue(
			$this->buildQueue()->isBuildPending($feedId),
			'Edits arriving during the wait must ride on the build that is already coming.'
		);
	}

	/**
	 * A feed edited faster than it builds would otherwise re-queue itself forever. It falls back to its
	 * interval, which is inside the freshness budget.
	 */
	public function testABuildStopsComingBackAfterTooManyStandDowns(): void
	{
		$feed = $this->makeFeed('requeueCeiling');
		$feedId = (int) $feed->id;

		$queuedBefore = $this->queuedBuildCount();

		$this->assertFalse($this->buildQueue()->requeueBuild($feedId, 5));
		$this->assertSame($queuedBefore, $this->queuedBuildCount(), 'Nothing more is queued once it gives up.');
	}

	public function testAFeedIsDueOnceItsLastBuildIsOlderThanTheInterval(): void
	{
		$feed = $this->makeFeed('due');
		$settings = $this->plugin()->getSettings();

		$this->assertTrue($this->buildQueue()->isDue($feed), 'A feed that has never built is due.');

		$feed->lastBuildStatus = BuildStatus::Ok->value;
		$feed->lastBuildFinishedAt = new DateTime('-10 minutes');

		$settings->buildInterval = 3600;
		$this->assertFalse($this->buildQueue()->isDue($feed));

		$settings->buildInterval = 300;
		$this->assertTrue($this->buildQueue()->isDue($feed));
	}

	/**
	 * A worker killed mid-build never clears the status, so without the timeout the feed never rebuilds.
	 */
	public function testAStalledBuildBecomesDueAgainAfterTheTimeout(): void
	{
		$feed = $this->makeFeed('stalled');
		$feed->lastBuildStatus = BuildStatus::Building->value;

		$feed->lastBuildStartedAt = new DateTime('-1 minute');
		$this->assertFalse($this->buildQueue()->isDue($feed), 'A build that just started is not stalled.');

		$timeout = $this->plugin()->getSettings()->buildTimeout;
		$feed->lastBuildStartedAt = new DateTime(sprintf('-%d seconds', $timeout + 60));
		$this->assertTrue($this->buildQueue()->isDue($feed));
	}
}
