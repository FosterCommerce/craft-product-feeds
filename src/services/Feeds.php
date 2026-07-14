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
use craft\helpers\UrlHelper;
use DateTime;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\BuildDiagnostics;
use fostercommerce\productfeeds\models\BuildResult;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\Settings;
use fostercommerce\productfeeds\models\UrlCheck;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\records\Feed as FeedRecord;
use yii\base\Exception;
use yii\base\InvalidArgumentException;

class Feeds extends Component
{
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

	/**
	 * Every site's feed of that handle: a handle is only unique within its site.
	 *
	 * @return Feed[]
	 */
	public function getFeedsByHandle(string $handle): array
	{
		return $this->findFeeds([
			'handle' => $handle,
		]);
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

		$record = $feed->id === null ? new FeedRecord() : $this->requireRecord($feed);

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
		$record = $this->requireRecord($feed);
		$record->lastBuildStatus = $status->value;

		if ($startedAt instanceof DateTime) {
			$record->lastBuildStartedAt = Db::prepareDateForDb($startedAt);
		}

		// A build in progress keeps the previous run's numbers: the old feed is still the one being served.
		if ($status === BuildStatus::Building) {
			$record->save(false);

			return;
		}

		$record->lastBuildFinishedAt = Db::prepareDateForDb(new DateTime());
		$record->lastBuildItemCount = $result?->itemCount;
		$record->lastBuildSkippedCount = $result?->buildDiagnostics->skippedCount();
		$record->lastBuildBytes = $result?->bytes;
		$record->lastBuildBytesUncompressed = $result?->bytesUncompressed;
		$record->lastBuildError = $error;
		$record->lastBuildDiagnostics = Json::encode($result?->buildDiagnostics->toArray() ?? []);
		$record->save(false);
	}

	public function recordUrlCheck(Feed $feed, UrlCheck $urlCheck): void
	{
		$record = $this->requireRecord($feed);
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

		$this->deletePaths([$feed->getPath(), $feed->getExcludedReportPath()]);

		FeedRecord::deleteAll([
			'id' => $id,
		]);
	}

	/**
	 * @throws Exception
	 */
	public function duplicateFeed(Feed $feed): ?Feed
	{
		// A copy carries every setting across. Only what has to be unique differs.
		$duplicate = clone $feed;
		$duplicate->id = null;
		$duplicate->uid = null;
		$duplicate->name = Craft::t(ProductFeeds::HANDLE, 'feed.copyOf', [
			'name' => $feed->name,
		]);
		$duplicate->handle = $this->uniqueHandle($feed->handle, (int) $feed->siteId);
		$duplicate->token = $this->generateToken();
		$duplicate->enabled = false;
		$duplicate->sortOrder = null;

		// The copy has never been built, and `saveFeed()` doesn't write the build columns, so the new row
		// comes up at its defaults. The clone has to be reset to match, diagnostics included: `clone` is
		// shallow, so both feeds would otherwise share one `BuildDiagnostics`.
		$duplicate->lastBuildStatus = BuildStatus::Pending->value;
		$duplicate->lastBuildStartedAt = null;
		$duplicate->lastBuildFinishedAt = null;
		$duplicate->lastBuildItemCount = null;
		$duplicate->lastBuildSkippedCount = null;
		$duplicate->lastBuildBytes = null;
		$duplicate->lastBuildBytesUncompressed = null;
		$duplicate->lastBuildError = null;
		$duplicate->lastBuildDiagnostics = new BuildDiagnostics();

		return $this->saveFeed($duplicate) ? $duplicate : null;
	}

	/**
	 * Gives a feed a new token, moving its artifact to the URL the new token serves from.
	 *
	 * @throws Exception
	 * @throws FsException
	 */
	public function rotateToken(Feed $feed): bool
	{
		// The path derives from the token.
		$previousToken = $feed->token;
		$previousPaths = [$feed->getPath(), $feed->getExcludedReportPath()];

		$feed->token = $this->generateToken();
		$rotatedPaths = [$feed->getPath(), $feed->getExcludedReportPath()];

		$this->copyPaths(array_combine($previousPaths, $rotatedPaths));

		if (! $this->saveFeed($feed)) {
			$feed->token = $previousToken;
			$this->deletePaths($rotatedPaths);

			return false;
		}

		$this->deletePaths($previousPaths);

		return true;
	}

	/**
	 * Whether a feed has somewhere to be written. A handle naming a filesystem that no longer exists does
	 * not count: a build would fail on it.
	 */
	public function hasFs(): bool
	{
		return $this->findFs() instanceof FsInterface;
	}

	/**
	 * @throws FeedBuildException
	 */
	public function getFs(): FsInterface
	{
		$handle = $this->fsHandle();

		if ($handle === null) {
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
	 * The filesystem, or null where the plugin has none configured.
	 */
	public function findFs(): ?FsInterface
	{
		try {
			return $this->getFs();
		} catch (FeedBuildException) {
			return null;
		}
	}

	/**
	 * Always the plugin's own route: the filesystem need not have a public URL, and the route also
	 * serves the inflated feed.
	 *
	 * @throws Exception
	 */
	public function getFeedUrl(Feed $feed): string
	{
		return UrlHelper::siteUrl($feed->getUrlPath(), null, null, $feed->siteId);
	}

	/**
	 * `StringHelper::randomString()` draws from 26 lowercase letters; the security component's alphabet is
	 * far wider for the same length.
	 *
	 * @throws Exception
	 */
	public function generateToken(): string
	{
		return Craft::$app->getSecurity()->generateRandomString(Feed::TOKEN_LENGTH);
	}

	/**
	 * @param int[] $feedIds in their new order
	 */
	public function reorderFeeds(array $feedIds): void
	{
		foreach ($feedIds as $sortOrder => $feedId) {
			FeedRecord::updateAll([
				'sortOrder' => $sortOrder + 1,
			], [
				'id' => $feedId,
			]);
		}
	}

	/**
	 * @throws InvalidArgumentException if the feed no longer exists
	 */
	private function requireRecord(Feed $feed): FeedRecord
	{
		$record = FeedRecord::findOne([
			'id' => $feed->id,
		]);

		if (! $record instanceof FeedRecord) {
			// Deleting the feed mid-build gets here. Writing to a fresh record would insert a second row
			// under the same handle instead.
			throw new InvalidArgumentException(sprintf('Feed %s no longer exists.', $feed->id));
		}

		return $record;
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
	 * @param array<string, string> $paths source path => destination path
	 * @throws FsException
	 */
	private function copyPaths(array $paths): void
	{
		$fs = $this->findFs();
		if (! $fs instanceof FsInterface) {
			return;
		}

		foreach ($paths as $path => $destination) {
			if ($fs->fileExists($path)) {
				$fs->copyFile($path, $destination);
			}
		}
	}

	/**
	 * @param string[] $paths
	 * @throws FsException
	 */
	private function deletePaths(array $paths): void
	{
		$fs = $this->findFs();
		if (! $fs instanceof FsInterface) {
			return;
		}

		foreach ($paths as $path) {
			if ($fs->fileExists($path)) {
				$fs->deleteFile($path);
			}
		}
	}

	private function settings(): Settings
	{
		return ProductFeeds::plugin()->getSettings();
	}

	/**
	 * The settings form requires a handle, but a config file can still set it to an empty string.
	 */
	private function fsHandle(): ?string
	{
		$handle = $this->settings()->fsHandle;

		return $handle === '' ? null : $handle;
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
		$feed->fieldMapping = Mapping::normalizeRows($this->decodeArray($record->fieldMapping));
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
	 * The column holds UTC, and `DateTimeHelper` reads a bare datetime as UTC where `DateTime` would use
	 * the request's timezone.
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
