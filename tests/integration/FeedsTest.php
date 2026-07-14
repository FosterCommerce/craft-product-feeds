<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use DateTime;
use fostercommerce\productfeeds\enums\Availability;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\helpers\Mapping;
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

	public function testRecordBuildStoresTheResultAndReadsItBack(): void
	{
		$feed = $this->makeFeed('record');

		$diagnostics = new BuildDiagnostics();
		$diagnostics->countBlank('description');
		$diagnostics->countBlank('description');
		$diagnostics->countInvalid('price');
		$diagnostics->countSkipped('image_link');
		$diagnostics->recordSkippedSample(4321, 'image_link');

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
	 * The artifact's path derives from the token, so rotating one without moving the other leaves the new
	 * URL serving a 404 until the next build.
	 */
	public function testRotateTokenMovesTheArtifactToTheNewUrl(): void
	{
		$feed = $this->buildableFeed('rotateArtifact', Platform::Google, [
			'condition' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => 'new',
			],
			'availability' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => Availability::InStock->value,
			],
		]);

		$this->buildOrSkip($feed);

		$previousPath = $feed->getPath();
		$fs = $this->feeds()->getFs();
		$this->assertTrue($fs->fileExists($previousPath));

		$this->assertTrue($this->feeds()->rotateToken($feed));

		$this->assertTrue($fs->fileExists($feed->getPath()), 'The new URL serves nothing: the artifact did not move.');
		$this->assertFalse($fs->fileExists($previousPath), 'The old artifact is still downloadable.');
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

	/**
	 * The copy has never been built, and `saveFeed()` doesn't write the build columns, so the row comes up
	 * at its defaults. `clone` is shallow, so the model has to be reset to match: it once carried the source
	 * feed's numbers and shared its `BuildDiagnostics` by reference.
	 */
	public function testDuplicateCarriesNoneOfTheSourceFeedsBuildHistory(): void
	{
		$feed = $this->makeFeed('dupeHistory');
		$feed->lastBuildDiagnostics->countSkipped('image_link');
		$this->feeds()->recordBuild($feed, BuildStatus::Ok, new DateTime(), new BuildResult(
			itemCount: 12,
			bytes: 3400,
			bytesUncompressed: 9000,
			buildDiagnostics: $feed->lastBuildDiagnostics,
		));

		$built = $this->feeds()->getFeedById((int) $feed->id);
		$this->assertNotNull($built);
		$this->assertSame(12, $built->lastBuildItemCount);

		$duplicate = $this->feeds()->duplicateFeed($built);
		$this->assertNotNull($duplicate);

		$this->assertSame(BuildStatus::Pending->value, $duplicate->lastBuildStatus);
		$this->assertNull($duplicate->lastBuildItemCount);
		$this->assertNull($duplicate->lastBuildSkippedCount);
		$this->assertNull($duplicate->lastBuildBytes);
		$this->assertNull($duplicate->lastBuildFinishedAt);
		$this->assertNull($duplicate->lastBuildError);
		$this->assertSame(0, $duplicate->lastBuildDiagnostics->skippedCount());

		// The shallow clone shared one BuildDiagnostics, so counting on the copy also moved the original.
		$this->assertNotSame($built->lastBuildDiagnostics, $duplicate->lastBuildDiagnostics);

		$reloaded = $this->feeds()->getFeedById((int) $duplicate->id);
		$this->assertNotNull($reloaded);
		$this->assertNull($reloaded->lastBuildItemCount, 'The saved row carries no build history either.');
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
