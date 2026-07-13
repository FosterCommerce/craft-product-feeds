<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\services;

use Craft;
use craft\base\Component;
use craft\base\FsInterface;
use craft\errors\FsException;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use DateTime;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\jobs\BuildFeed;
use fostercommerce\productfeeds\models\BuildDiagnostics;
use fostercommerce\productfeeds\models\BuildResult;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\Settings;
use fostercommerce\productfeeds\models\UrlCheck;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\records\Feed as FeedRecord;
use yii\base\Exception;

class Feeds extends Component
{
	/**
	 * A build is queued but has not started. Long enough to outlive a backed-up queue: an expiry queues a
	 * second job for the same feed, which the build lock then reduces to a redundant rebuild.
	 */
	private const PENDING_TTL = 300;

	/**
	 * An edit landed while a build was already running.
	 */
	private const DIRTY_TTL = 7200;

	/**
	 * @return Feed[]
	 */
	public function getAllFeeds(): array
	{
		return $this->findFeeds();
	}

	/**
	 * @return Feed[]
	 */
	public function getFeedsBySiteId(int $siteId): array
	{
		return $this->findFeeds([
			'siteId' => $siteId,
		]);
	}

	/**
	 * @return Feed[]
	 */
	public function getEnabledFeeds(): array
	{
		return $this->findFeeds([
			'enabled' => true,
		]);
	}

	public function getFeedById(int $id): ?Feed
	{
		$record = FeedRecord::findOne([
			'id' => $id,
		]);

		return $record === null ? null : $this->toModel($record);
	}

	public function getFeedByToken(string $token): ?Feed
	{
		$record = FeedRecord::findOne([
			'token' => $token,
		]);

		return $record === null ? null : $this->toModel($record);
	}

	/**
	 * @throws Exception
	 */
	public function saveFeed(Feed $feed): bool
	{
		if ($feed->token === '') {
			$feed->token = $this->generateToken();
		}

		if (! $feed->validate()) {
			return false;
		}

		$record = $feed->id === null
			? new FeedRecord()
			: FeedRecord::findOne([
				'id' => $feed->id,
			]) ?? new FeedRecord();

		$record->name = $feed->name;
		$record->handle = $feed->handle;
		$record->platform = $feed->platform;
		$record->source = $feed->source;
		$record->siteId = (int) $feed->siteId;
		$record->sourceIds = Json::encode($feed->sourceIds);
		$record->fieldMapping = Json::encode($feed->fieldMapping);
		$record->filterCondition = Json::encode($feed->filterCondition);
		$record->imageEngine = $feed->imageEngine;
		$record->imageTransform = $feed->imageTransform;
		$record->imageWidth = $feed->imageWidth;
		$record->imageHeight = $feed->imageHeight;
		$record->imageFit = $feed->imageFit;
		$record->token = $feed->token;
		$record->enabled = $feed->enabled;
		// A null sortOrder sorts first on MySQL and last on PostgreSQL, so a new feed would land at a
		// different end of the index depending on the driver.
		$record->sortOrder = $feed->sortOrder ?? $this->nextSortOrder();
		$record->save(false);

		$feed->id = $record->id;
		$feed->uid = $record->uid;

		return true;
	}

	public function recordBuild(
		Feed $feed,
		BuildStatus $status,
		?DateTime $startedAt = null,
		?BuildResult $result = null,
		?string $error = null,
	): void {
		$record = FeedRecord::findOne([
			'id' => $feed->id,
		]);

		if ($record === null) {
			return;
		}

		$record->lastBuildStatus = $status->value;
		$record->lastBuildStartedAt = $startedAt instanceof DateTime ? Db::prepareDateForDb($startedAt) : $record->lastBuildStartedAt;

		// A build in progress keeps the previous run's numbers: the old feed is still the one being served.
		if ($status === BuildStatus::Building) {
			$record->save(false);

			return;
		}

		$record->lastBuildFinishedAt = Db::prepareDateForDb(new DateTime());
		$record->lastBuildItemCount = $result?->itemCount;
		$record->lastBuildSkippedCount = $result?->skippedCount();
		$record->lastBuildBytes = $result?->bytes;
		$record->lastBuildBytesUncompressed = $result?->bytesUncompressed;
		$record->lastBuildError = $error;
		$record->lastBuildDiagnostics = Json::encode($result?->buildDiagnostics->toArray() ?? []);
		$record->save(false);
	}

	/**
	 * Kept apart from `recordBuild()` because the check only means anything once the feed row carries the
	 * artifact's size: the route 404s until it does.
	 */
	public function recordUrlCheck(Feed $feed, UrlCheck $urlCheck): void
	{
		$record = FeedRecord::findOne([
			'id' => $feed->id,
		]);

		if ($record === null) {
			return;
		}

		$diagnostics = BuildDiagnostics::fromArray($this->decodeArray($record->lastBuildDiagnostics));
		$diagnostics->urlCheck = $urlCheck;

		$record->lastBuildDiagnostics = Json::encode($diagnostics->toArray());
		$record->save(false);
	}

	/**
	 * @throws FsException
	 */
	public function deleteFeedById(int $id): void
	{
		$feed = $this->getFeedById($id);
		if (! $feed instanceof Feed) {
			return;
		}

		$this->deleteArtifact($feed);

		FeedRecord::deleteAll([
			'id' => $id,
		]);
	}

	/**
	 * @throws Exception
	 */
	public function duplicateFeed(Feed $feed): ?Feed
	{
		$duplicate = new Feed([
			'name' => Craft::t(ProductFeeds::HANDLE, 'feed.copyOf', [
				'name' => $feed->name,
			]),
			'handle' => $this->uniqueHandle($feed->handle, (int) $feed->siteId),
			'platform' => $feed->platform,
			'source' => $feed->source,
			'siteId' => $feed->siteId,
			'sourceIds' => $feed->sourceIds,
			'fieldMapping' => $feed->fieldMapping,
			'filterCondition' => $feed->filterCondition,
			'imageEngine' => $feed->imageEngine,
			'imageTransform' => $feed->imageTransform,
			'imageWidth' => $feed->imageWidth,
			'imageHeight' => $feed->imageHeight,
			'imageFit' => $feed->imageFit,
			'token' => $this->generateToken(),
			'enabled' => false,
		]);

		return $this->saveFeed($duplicate) ? $duplicate : null;
	}

	/**
	 * The old artifact's paths are taken before the token is minted, because `Feed::getPath()` derives
	 * from the token. They are only deleted once the new token is persisted, so a failed save leaves the
	 * feed serving the file its stored token still points at.
	 *
	 * @throws Exception
	 * @throws FsException
	 */
	public function rotateToken(Feed $feed): bool
	{
		$previousToken = $feed->token;
		$previousPaths = [$feed->getPath(), $feed->getExcludedReportPath()];

		$feed->token = $this->generateToken();

		if (! $this->saveFeed($feed)) {
			$feed->token = $previousToken;

			return false;
		}

		$this->deletePaths($previousPaths);

		return true;
	}

	/**
	 * @throws FsException
	 */
	public function deleteArtifact(Feed $feed): void
	{
		$this->deletePaths([$feed->getPath(), $feed->getExcludedReportPath()]);
	}

	/**
	 * @throws FeedBuildException
	 */
	public function getFs(): FsInterface
	{
		$handle = $this->settings()->fsHandle;

		if ($handle === null || $handle === '') {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.noFilesystem'));
		}

		$fs = Craft::$app->getFs()->getFilesystemByHandle($handle);
		if ($fs === null) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.missingFilesystem', [
				'handle' => $handle,
			]));
		}

		return $fs;
	}

	/**
	 * Always the plugin's own route: the filesystem the feed is written to need not have a public URL,
	 * and the route also serves the inflated feed.
	 *
	 * @throws Exception
	 */
	public function getFeedUrl(Feed $feed): string
	{
		return UrlHelper::siteUrl(
			sprintf('%s/%s-%s.%s.gz', ProductFeeds::FILE_PREFIX, $feed->handle, $feed->token, $feed->getSpec()->fileExtension()),
			null,
			null,
			$feed->siteId
		);
	}

	/**
	 * The token is the only credential on the public feed route, so it comes from Craft's CSPRNG rather
	 * than `StringHelper::randomString()`, which is not cryptographically secure.
	 *
	 * @throws Exception
	 */
	public function generateToken(): string
	{
		return Craft::$app->getSecurity()->generateRandomString(Feed::TOKEN_LENGTH);
	}

	/**
	 * @param int[] $ids in their new order
	 */
	public function reorderFeeds(array $ids): void
	{
		foreach ($ids as $sortOrder => $id) {
			FeedRecord::updateAll([
				'sortOrder' => $sortOrder + 1,
			], [
				'id' => $id,
			]);
		}
	}

	public function enqueueDueBuilds(): int
	{
		$interval = $this->settings()->buildInterval;
		$queued = 0;

		foreach ($this->getEnabledFeeds() as $feed) {
			if (! $this->isDue($feed, $interval)) {
				continue;
			}

			$this->requestBuild((int) $feed->id);
			$queued++;
		}

		return $queued;
	}

	public function isDue(Feed $feed, int $interval): bool
	{
		$now = DateTimeHelper::currentUTCDateTime()->getTimestamp();

		if ($feed->getLastBuildStatus() === BuildStatus::Building) {
			// A worker killed mid-build never clears the status, so without a timeout the feed never rebuilds.
			$timeout = $this->settings()->buildTimeout;
			$startedAt = $feed->lastBuildStartedAt;

			return $startedAt instanceof DateTime && $now - $startedAt->getTimestamp() > $timeout;
		}

		// A flag set just as the build that would have consumed it was finishing has no job left to act on
		// it, so the scheduled pass takes it rather than the edit waiting a full interval.
		if ($this->isBuildDirty((int) $feed->id)) {
			return true;
		}

		if (! $feed->lastBuildFinishedAt instanceof DateTime) {
			return true;
		}

		return $now - $feed->lastBuildFinishedAt->getTimestamp() >= $interval;
	}

	/**
	 * Queues a build now, unless one is already queued and waiting to start. Saving a product fires an
	 * element save per variant, and this collapses those into one build.
	 */
	public function requestBuild(int $feedId): void
	{
		$cache = Craft::$app->getCache();
		$key = $this->pendingKey($feedId);

		if ($cache !== null) {
			if ($cache->get($key) !== false) {
				return;
			}

			$cache->set($key, true, self::PENDING_TTL);
		}

		Queue::push(new BuildFeed([
			'feedId' => $feedId,
		]));
	}

	public function isBuildPending(int $feedId): bool
	{
		$cache = Craft::$app->getCache();

		return $cache !== null && $cache->get($this->pendingKey($feedId)) !== false;
	}

	/**
	 * Drops the flag that collapses a burst of edits into one queued build.
	 *
	 * Every job that stops absorbing edits must clear it, or `requestBuild()` no-ops until the flag
	 * expires and the edits made in that window wait for the next interval build.
	 */
	public function clearPending(int $feedId): void
	{
		Craft::$app->getCache()?->delete($this->pendingKey($feedId));
	}

	/**
	 * The lock a build holds, whether it runs in the queue or inline in the console. Two builds of one
	 * feed write the same temporary file and publish over each other.
	 *
	 * Keyed by ID, not handle: a handle is only unique within its site.
	 */
	public function buildLockName(Feed $feed): string
	{
		return sprintf('product-feeds:%d', $feed->id);
	}

	/**
	 * An edit arrived while a build was running, so that build's output is already stale.
	 *
	 * Whoever finishes that build has to call `requestBuildIfDirty()`. Where they finished before the flag
	 * was set, `isDue()` picks it up on the next scheduled pass instead.
	 */
	public function markBuildDirty(int $feedId): void
	{
		Craft::$app->getCache()?->set($this->dirtyKey($feedId), true, self::DIRTY_TTL);
	}

	public function isBuildDirty(int $feedId): bool
	{
		$cache = Craft::$app->getCache();

		return $cache !== null && $cache->get($this->dirtyKey($feedId)) !== false;
	}

	public function clearBuildDirty(int $feedId): void
	{
		Craft::$app->getCache()?->delete($this->dirtyKey($feedId));
	}

	/**
	 * Queues a follow-up build for the edits that landed while this one was running.
	 *
	 * Call it once the build lock is released, or the job queued here cannot take it.
	 */
	public function requestBuildIfDirty(int $feedId): void
	{
		if (! $this->isBuildDirty($feedId)) {
			return;
		}

		$this->clearBuildDirty($feedId);
		$this->requestBuild($feedId);
	}

	/**
	 * @param array<string, mixed> $condition
	 * @return Feed[]
	 */
	private function findFeeds(array $condition = []): array
	{
		$records = FeedRecord::find()
			->where($condition)
			->orderBy([
				'sortOrder' => SORT_ASC,
				'id' => SORT_ASC,
			])
			->all();

		/** @var FeedRecord[] $records */
		return array_values(array_map(fn (FeedRecord $record): Feed => $this->toModel($record), $records));
	}

	private function nextSortOrder(): int
	{
		// `max()` hands back whatever the PDO driver gave it, and null when the table is empty.
		$highest = FeedRecord::find()->max('[[sortOrder]]');

		return is_numeric($highest) ? (int) $highest + 1 : 1;
	}

	/**
	 * @param string[] $paths
	 * @throws FsException
	 */
	private function deletePaths(array $paths): void
	{
		try {
			$fs = $this->getFs();
		} catch (FeedBuildException) {
			return;
		}

		foreach ($paths as $path) {
			if ($fs->fileExists($path)) {
				$fs->deleteFile($path);
			}
		}
	}

	private function pendingKey(int $feedId): string
	{
		return sprintf('product-feeds:pending:%d', $feedId);
	}

	private function dirtyKey(int $feedId): string
	{
		return sprintf('product-feeds:dirty:%d', $feedId);
	}

	private function settings(): Settings
	{
		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();

		return $plugin->getSettings();
	}

	private function uniqueHandle(string $handle, int $siteId): string
	{
		$candidate = $handle;
		$suffix = 1;

		while (FeedRecord::find()->where([
			'handle' => $candidate,
			'siteId' => $siteId,
		])->exists()) {
			$suffix++;
			$candidate = sprintf('%s%d', $handle, $suffix);
		}

		return $candidate;
	}

	private function toModel(FeedRecord $record): Feed
	{
		$feed = new Feed();
		$feed->id = $record->id;
		$feed->name = $record->name;
		$feed->handle = $record->handle;
		$feed->platform = $record->platform;
		$feed->source = $record->source;
		$feed->siteId = $record->siteId;
		$feed->sourceIds = array_values(array_filter(array_map(
			static fn (mixed $sourceId): string => is_scalar($sourceId) ? (string) $sourceId : '',
			$this->decodeArray($record->sourceIds)
		)));
		$feed->fieldMapping = Mapping::rows($this->decodeArray($record->fieldMapping));
		$feed->filterCondition = $this->decodeArray($record->filterCondition);
		$feed->imageEngine = $record->imageEngine;
		$feed->imageTransform = $record->imageTransform;
		$feed->imageWidth = $record->imageWidth;
		$feed->imageHeight = $record->imageHeight;
		$feed->imageFit = $record->imageFit;
		$feed->token = $record->token;
		$feed->enabled = (bool) $record->enabled;
		$feed->sortOrder = $record->sortOrder;
		$feed->lastBuildStatus = $record->lastBuildStatus;
		$feed->lastBuildStartedAt = $this->toDateTime($record->lastBuildStartedAt);
		$feed->lastBuildFinishedAt = $this->toDateTime($record->lastBuildFinishedAt);
		$feed->lastBuildItemCount = $record->lastBuildItemCount;
		$feed->lastBuildSkippedCount = $record->lastBuildSkippedCount;
		$feed->lastBuildBytes = $record->lastBuildBytes;
		$feed->lastBuildBytesUncompressed = $record->lastBuildBytesUncompressed;
		$feed->lastBuildError = $record->lastBuildError;
		$feed->lastBuildDiagnostics = BuildDiagnostics::fromArray($this->decodeArray($record->lastBuildDiagnostics));
		$feed->uid = $record->uid;

		return $feed;
	}

	/**
	 * The column holds UTC and `DateTimeHelper` assumes UTC for a bare datetime string, where `DateTime`
	 * would read it in the request's timezone.
	 */
	private function toDateTime(?string $value): ?DateTime
	{
		if ($value === null || $value === '') {
			return null;
		}

		return DateTimeHelper::toDateTime($value) ?: null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeArray(?string $json): array
	{
		$decoded = Json::decodeIfJson((string) $json);

		if (! is_array($decoded)) {
			return [];
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}
}
