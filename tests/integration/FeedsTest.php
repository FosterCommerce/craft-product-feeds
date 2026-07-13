<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use DateTime;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\models\BuildDiagnostics;
use fostercommerce\productfeeds\models\BuildResult;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\UrlCheck;

class FeedsTest extends IntegrationTestCase
{
	public function testTokenIsMintedAndTheFeedUrlCarriesIt(): void
	{
		$feed = $this->makeFeed('token');

		$this->assertSame(Feed::TOKEN_LENGTH, strlen($feed->token));
		$this->assertStringContainsString($feed->handle . '-' . $feed->token, $this->feeds()->getFeedUrl($feed));
	}

	/**
	 * Saving a product fires an element save per variant. Without the pending flag each one would queue
	 * its own build of the same feed.
	 */
	public function testRequestBuildCollapsesABurstIntoOneQueuedBuild(): void
	{
		$feed = $this->makeFeed('pending');
		$feedId = (int) $feed->id;

		$this->assertFalse($this->feeds()->isBuildPending($feedId));

		$this->feeds()->requestBuild($feedId);
		$this->assertTrue($this->feeds()->isBuildPending($feedId));

		$queuedBefore = $this->queuedBuildCount();
		$this->feeds()->requestBuild($feedId);
		$this->feeds()->requestBuild($feedId);

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

		$this->feeds()->requestBuild($feedId);
		$this->feeds()->clearPending($feedId);

		$this->assertFalse($this->feeds()->isBuildPending($feedId));

		$queuedBefore = $this->queuedBuildCount();
		$this->feeds()->requestBuild($feedId);

		$this->assertSame($queuedBefore + 1, $this->queuedBuildCount());
	}

	/**
	 * An edit that lands mid-build is remembered, and whoever finishes that build queues a fresh one.
	 */
	public function testADirtyFeedQueuesAFollowUpBuild(): void
	{
		$feed = $this->makeFeed('dirty');
		$feedId = (int) $feed->id;

		$queuedBefore = $this->queuedBuildCount();

		$this->feeds()->requestBuildIfDirty($feedId);
		$this->assertSame($queuedBefore, $this->queuedBuildCount(), 'A clean feed queues nothing.');

		$this->feeds()->markBuildDirty($feedId);
		$this->feeds()->requestBuildIfDirty($feedId);
		$this->assertSame($queuedBefore + 1, $this->queuedBuildCount());

		// The flag is taken, not read, so the follow-up build does not queue a third.
		$this->feeds()->clearPending($feedId);
		$this->feeds()->requestBuildIfDirty($feedId);
		$this->assertSame($queuedBefore + 1, $this->queuedBuildCount());
	}

	public function testAFeedIsDueOnceItsLastBuildIsOlderThanTheInterval(): void
	{
		$feed = $this->makeFeed('due');

		$this->assertTrue($this->feeds()->isDue($feed, 3600), 'A feed that has never built is due.');

		$feed->lastBuildStatus = BuildStatus::Ok->value;
		$feed->lastBuildFinishedAt = new DateTime('-10 minutes');
		$this->assertFalse($this->feeds()->isDue($feed, 3600));
		$this->assertTrue($this->feeds()->isDue($feed, 300));
	}

	/**
	 * A worker killed mid-build never clears the status, so without the timeout the feed never rebuilds.
	 */
	public function testAStalledBuildBecomesDueAgainAfterTheTimeout(): void
	{
		$feed = $this->makeFeed('stalled');
		$feed->lastBuildStatus = BuildStatus::Building->value;

		$feed->lastBuildStartedAt = new DateTime('-1 minute');
		$this->assertFalse($this->feeds()->isDue($feed, 3600), 'A build that just started is not stalled.');

		$timeout = $this->plugin()->getSettings()->buildTimeout;
		$feed->lastBuildStartedAt = new DateTime(sprintf('-%d seconds', $timeout + 60));
		$this->assertTrue($this->feeds()->isDue($feed, 3600));
	}

	public function testRecordBuildStoresTheResultAndReadsItBack(): void
	{
		$feed = $this->makeFeed('record');

		$diagnostics = new BuildDiagnostics();
		$diagnostics->countBlank('description');
		$diagnostics->countBlank('description');
		$diagnostics->countInvalid('price');
		$diagnostics->countSkipped('image_link');
		$diagnostics->sampleSkipped(4321, 'image_link');

		$this->feeds()->recordBuild(
			$feed,
			BuildStatus::Ok,
			new DateTime(),
			new BuildResult(12, 3400, 91_000, $diagnostics)
		);

		$reloaded = $this->feeds()->getFeedById((int) $feed->id);

		$this->assertNotNull($reloaded);
		$this->assertSame(BuildStatus::Ok, $reloaded->getLastBuildStatus());
		$this->assertSame(12, $reloaded->lastBuildItemCount);
		$this->assertSame(1, $reloaded->lastBuildSkippedCount);
		$this->assertSame(3400, $reloaded->lastBuildBytes);
		$this->assertSame(91_000, $reloaded->lastBuildBytesUncompressed);
		$this->assertSame([
			'description' => 2,
		], $reloaded->lastBuildDiagnostics->blankByAttribute);
		$this->assertSame([
			'price' => 1,
		], $reloaded->lastBuildDiagnostics->invalidByAttribute);
		$this->assertSame([[
			'id' => 4321,
			'reason' => 'image_link',
		]], $reloaded->lastBuildDiagnostics->sampleSkipped);
	}

	/**
	 * The check is recorded after the build, and must not wipe the counts the build just stored.
	 */
	public function testRecordUrlCheckKeepsTheBuildsOwnDiagnostics(): void
	{
		$feed = $this->makeFeed('urlCheck');

		$diagnostics = new BuildDiagnostics();
		$diagnostics->countBlank('gtin');

		$this->feeds()->recordBuild($feed, BuildStatus::Ok, new DateTime(), new BuildResult(3, 100, 200, $diagnostics));
		$this->feeds()->recordUrlCheck($feed, new UrlCheck(200, 'application/gzip'));

		$reloaded = $this->feeds()->getFeedById((int) $feed->id);

		$this->assertNotNull($reloaded);

		$urlCheck = $reloaded->lastBuildDiagnostics->urlCheck;
		$this->assertNotNull($urlCheck);
		$this->assertSame(200, $urlCheck->status);
		$this->assertSame('application/gzip', $urlCheck->contentType);
		$this->assertSame([
			'gtin' => 1,
		], $reloaded->lastBuildDiagnostics->blankByAttribute);
	}

	/**
	 * A build in progress keeps the previous run's numbers: that feed is still the one being served.
	 */
	public function testABuildInProgressKeepsThePreviousRunsNumbers(): void
	{
		$feed = $this->makeFeed('inProgress');

		$this->feeds()->recordBuild($feed, BuildStatus::Ok, new DateTime(), new BuildResult(7, 100, 200, new BuildDiagnostics()));
		$this->feeds()->recordBuild($feed, BuildStatus::Building, new DateTime());

		$reloaded = $this->feeds()->getFeedById((int) $feed->id);

		$this->assertNotNull($reloaded);
		$this->assertSame(BuildStatus::Building, $reloaded->getLastBuildStatus());
		$this->assertSame(7, $reloaded->lastBuildItemCount);
	}

	public function testRotateTokenMintsANewTokenAndReportsIt(): void
	{
		$feed = $this->makeFeed('rotate');
		$originalToken = $feed->token;

		$this->assertTrue($this->feeds()->rotateToken($feed));
		$this->assertNotSame($originalToken, $feed->token);

		$reloaded = $this->feeds()->getFeedById((int) $feed->id);
		$this->assertNotNull($reloaded);
		$this->assertSame($feed->token, $reloaded->token);
	}

	/**
	 * The artifact is deleted from a path derived from the old token, so a failed save has to leave the
	 * stored token, and its file, alone.
	 */
	public function testRotateTokenLeavesTheStoredTokenAloneWhenTheSaveFails(): void
	{
		$feed = $this->makeFeed('rotateFail');
		$originalToken = $feed->token;

		// `name` is required, so this is the cheapest way to make the save fail.
		$feed->name = '';

		$this->assertFalse($this->feeds()->rotateToken($feed));
		$this->assertSame($originalToken, $feed->token, 'The in-memory token is put back.');

		$reloaded = $this->feeds()->getFeedById((int) $feed->id);
		$this->assertNotNull($reloaded);
		$this->assertSame($originalToken, $reloaded->token);
	}

	public function testDuplicateGetsItsOwnHandleAndTokenAndLandsDisabled(): void
	{
		$feed = $this->makeFeed('dupe');
		$duplicate = $this->feeds()->duplicateFeed($feed);

		$this->assertNotNull($duplicate);
		$this->assertNotSame($feed->handle, $duplicate->handle);
		$this->assertNotSame($feed->token, $duplicate->token);
		$this->assertFalse($duplicate->enabled);
	}

	public function testAHandleCannotBeTakenTwice(): void
	{
		$this->makeFeed('unique');

		$clash = new Feed([
			'name' => 'Clash',
			'handle' => self::HANDLE_PREFIX . 'Unique',
			'siteId' => $this->primarySiteId(),
		]);

		$this->assertFalse($this->feeds()->saveFeed($clash));
		$this->assertArrayHasKey('handle', $clash->getErrors());
	}

	/**
	 * Re-saving an existing feed must not trip its own uniqueness check.
	 */
	public function testAFeedCanBeSavedAgainUnderItsOwnHandle(): void
	{
		$feed = $this->makeFeed('resave');
		$feed->name = 'Renamed';

		$this->assertTrue($this->feeds()->saveFeed($feed), (string) json_encode($feed->getErrors()));
	}
}
