<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FsInterface;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\models\Store;
use craft\db\Query;
use craft\db\Table;
use craft\errors\FsException;
use craft\helpers\FileHelper;
use craft\helpers\ImageTransforms;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FsListing;
use craft\models\ImageTransform as CraftImageTransform;
use DateTime;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\enums\ImageEngine;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\feeds\AttributeDefinition;
use fostercommerce\productfeeds\feeds\ExcludedReport;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\feeds\FeedWriter;
use fostercommerce\productfeeds\feeds\ItemBuilder;
use fostercommerce\productfeeds\helpers\Gzip;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\BuildDiagnostics;
use fostercommerce\productfeeds\models\BuildResult;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\ImageTestResult;
use fostercommerce\productfeeds\models\ImageTransform;
use fostercommerce\productfeeds\models\QueuedBuildState;
use fostercommerce\productfeeds\models\UrlCheck;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\CustomSource;
use fostercommerce\productfeeds\sources\FeedSource;
use fostercommerce\productfeeds\sources\MissingSource;
use fostercommerce\productfeeds\sources\SuppliedItem;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class Builds extends Component
{
	private const BATCH_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

	/**
	 * The entry point for a live build in one process: the console command. Queued builds run one batch per
	 * queue job, through `startQueuedBuild()`.
	 *
	 * Standing down is the caller's to handle: the console reports it.
	 *
	 * @param (callable(int, int): void)|null $onProgress
	 * @return BuildResult|null null when another build already holds the lock
	 * @throws FeedBuildException
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function buildUnderLock(Feed $feed, ?callable $onProgress = null): ?BuildResult
	{
		$buildQueue = ProductFeeds::plugin()->getBuildQueue();
		$feedId = (int) $feed->id;

		$mutex = Craft::$app->getMutex();
		$lockName = $buildQueue->buildLockName($feedId);

		if (! $mutex->acquire($lockName)) {
			return null;
		}

		// Clear the pending flag, since this build reads the current catalog and a later edit needs its own build.
		$buildQueue->clearPending($feedId);

		try {
			return $this->buildAndRecord($feed, $onProgress);
		} finally {
			$mutex->release($lockName);
		}
	}

	/**
	 * Streams the whole catalog into the artifact and publishes it. Takes no lock and records nothing:
	 * a live build goes through `buildUnderLock()` or the queued build methods.
	 *
	 * @param (callable(int, int): void)|null $onProgress
	 * @throws FeedBuildException on a configuration error that retrying cannot fix
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function build(Feed $feed, ?callable $onProgress = null): BuildResult
	{
		$spec = $feed->getSpec();
		$source = FeedSource::forFeed($feed);
		$this->assertBuildable($feed, $spec, $source);

		// Resolved up front: a missing filesystem is a configuration error, and finding out at publish time
		// would waste a whole pass over the catalog.
		$fs = $this->feeds()->getFs();

		$tempDirectory = Craft::$app->getPath()->getTempPath();
		$tempPath = sprintf('%s/%s', $tempDirectory, $feed->getFileName());
		$reportPath = sprintf('%s/%s', $tempDirectory, $feed->getExcludedReportFileName());

		$diagnostics = new BuildDiagnostics();
		$writer = $spec->writer($tempPath, $feed->name, $feed->getSiteBaseUrl());
		$report = new ExcludedReport($reportPath);
		$itemBuilder = new ItemBuilder($feed, $spec, $source, $diagnostics);

		$itemCount = $this->writeItems($source, $writer, $report, $itemBuilder, $onProgress);

		return $this->publish($fs, $feed, $tempPath, $reportPath, $itemCount, $diagnostics);
	}

	/**
	 * Starts a build the queue runs one batch per job, so no queue run walks the whole catalog. Call it
	 * holding the feed's lock.
	 *
	 * @return string the build's ID, which every later run of the build passes back
	 * @throws FeedBuildException
	 * @throws FsException
	 * @throws InvalidConfigException
	 */
	public function startQueuedBuild(Feed $feed): string
	{
		// Clear the pending flag before the checks, so a build that fails to start doesn't block the next request
		ProductFeeds::plugin()->getBuildQueue()->clearPending((int) $feed->id);

		$source = FeedSource::forFeed($feed);
		$this->assertBuildable($feed, $feed->getSpec(), $source);
		$fs = $this->feeds()->getFs();

		$this->deleteExpiredQueuedBuilds($fs, $feed);

		$startedAt = new DateTime();
		$buildId = sprintf('%d-%s', $startedAt->getTimestamp(), StringHelper::randomString(8));

		$elementIds = array_map(intval(...), $source->query()->ids());
		$state = new QueuedBuildState(array_chunk($elementIds, max(1, ProductFeeds::plugin()->getSettings()->batchSize)));

		$this->feeds()->recordBuild($feed, BuildStatus::Building, $startedAt);

		// Save the state last. A saved state marks the build as running, and a build that failed to start
		// would otherwise make new builds stand down until it expires.
		$fs->write($this->statePath($feed, $buildId), $state->toJson());

		return $buildId;
	}

	/**
	 * Builds the next batch of a queued build and saves its items beside the build's state. Call it holding
	 * the feed's lock.
	 *
	 * @throws FeedBuildException
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function buildNextQueuedBatch(Feed $feed, string $buildId): QueuedBuildState
	{
		$fs = $this->feeds()->getFs();
		$state = $this->queuedBuildState($fs, $feed, $buildId);

		// A retried run finds its batch already saved, since the state is written after the batch.
		if (! $state->hasMoreBatches()) {
			return $state;
		}

		$source = FeedSource::forFeed($feed);
		$diagnostics = BuildDiagnostics::fromArray($state->diagnostics);
		$itemBuilder = new ItemBuilder($feed, $feed->getSpec(), $source, $diagnostics);

		/** @var ElementInterface[] $elements */
		$elements = $source->query()
			->id($state->elementIdBatches[$state->nextBatch])
			->fixedOrder()
			->all();
		$source->prepareBatch($elements);

		$batch = [
			'items' => [],
			'excluded' => [],
		];
		foreach ($elements as $element) {
			$elementItems = $this->itemsForElement($source, $itemBuilder, $element, $state->publishedIds);
			array_push($batch['items'], ...$elementItems['items']);
			array_push($batch['excluded'], ...$elementItems['excluded']);
		}

		$fs->write($this->batchPath($feed, $buildId, $state->nextBatch), Json::encode($batch, self::BATCH_JSON_FLAGS));

		$state->diagnostics = $diagnostics->toArray();
		$state->nextBatch++;
		$fs->write($this->statePath($feed, $buildId), $state->toJson());

		return $state;
	}

	/**
	 * Writes a queued build's batches into the feed, publishes it and records the result. Call it holding
	 * the feed's lock, once every batch is built.
	 *
	 * @throws Exception
	 * @throws FeedBuildException
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function finishQueuedBuild(Feed $feed, string $buildId): BuildResult
	{
		$fs = $this->feeds()->getFs();
		$state = $this->queuedBuildState($fs, $feed, $buildId);

		$tempDirectory = Craft::$app->getPath()->getTempPath();
		$tempPath = sprintf('%s/%s', $tempDirectory, $feed->getFileName());
		$reportPath = sprintf('%s/%s', $tempDirectory, $feed->getExcludedReportFileName());

		$writer = $feed->getSpec()->writer($tempPath, $feed->name, $feed->getSiteBaseUrl());
		$report = new ExcludedReport($reportPath);
		$itemCount = 0;

		$writer->open();

		try {
			foreach (array_keys($state->elementIdBatches) as $batchIndex) {
				/** @var array{items: list<array<string, string|list<string>>>, excluded: list<array{id: string, title: string, cpUrl: string, issue: string}>} $batch */
				$batch = Json::decode($fs->read($this->batchPath($feed, $buildId, $batchIndex)));

				foreach ($batch['items'] as $item) {
					$writer->writeItem($item);
					$itemCount++;
				}

				foreach ($batch['excluded'] as $reportRow) {
					$report->write($reportRow);
				}

				$writer->flush();
			}

			$writer->close();
			$report->close();
		} catch (Throwable $throwable) {
			$writer->abort();
			$report->abort();

			throw $throwable;
		}

		$result = $this->publish($fs, $feed, $tempPath, $reportPath, $itemCount, BuildDiagnostics::fromArray($state->diagnostics));

		// The route 404s until the feed row carries the artifact's size.
		$feeds = $this->feeds();
		$feeds->recordBuild($feed, BuildStatus::Ok, result: $result);
		$feeds->recordUrlCheck($feed, $this->checkFeedUrl($feeds->getFeedUrl($feed)));

		$fs->deleteDirectory($this->queuedBuildDirectory($feed, $buildId));

		return $result;
	}

	/**
	 * Logs the error in full and records the failure on the feed, where the feeds index shows its message.
	 *
	 * @throws InvalidConfigException
	 */
	public function recordQueuedBuildFailure(Feed $feed, ?string $buildId, Throwable $throwable): void
	{
		Craft::error(sprintf('Building the “%s” product feed failed: %s', $feed->handle, $throwable), ProductFeeds::HANDLE);

		$this->feeds()->recordBuild($feed, BuildStatus::Failed, error: $throwable->getMessage());

		// Delete the state after a configuration error, which the queue never retries
		if ($buildId !== null && $throwable instanceof FeedBuildException) {
			$this->feeds()->findFs()?->deleteDirectory($this->queuedBuildDirectory($feed, $buildId));
		}
	}

	/**
	 * Whether a queued build's state is still saved. A later build deletes the state of a build older than the
	 * build timeout, and that build's remaining queue jobs have no batches left to build.
	 *
	 * @throws FeedBuildException
	 * @throws FsException
	 * @throws InvalidConfigException
	 */
	public function queuedBuildExists(Feed $feed, string $buildId): bool
	{
		return $this->feeds()->getFs()->fileExists($this->statePath($feed, $buildId));
	}

	/**
	 * Whether a queued build of the feed started within the build timeout and has not finished.
	 *
	 * @throws FsException
	 * @throws InvalidConfigException
	 */
	public function isQueuedBuildRunning(Feed $feed): bool
	{
		$fs = $this->feeds()->findFs();

		return $fs instanceof FsInterface && $this->queuedBuildIds($fs, $feed, expired: false) !== [];
	}

	/**
	 * The first items a build would publish, with the reason it would skip each one it cannot. Blank
	 * attributes are dropped from an item, so without the reason a skipped item previews as complete.
	 *
	 * @return list<array{elementId: int, item: array<string, string|list<string>>, missing: ?string, duplicate: bool}>
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function preview(Feed $feed, int $limit = 10): array
	{
		$spec = $feed->getSpec();
		$source = FeedSource::forFeed($feed);

		/** @var ElementInterface[] $elements */
		$elements = $source->query()->limit($limit)->all();
		$source->prepareBatch($elements);

		// The preview shows the items, not the counts, so these are collected and dropped.
		$itemBuilder = new ItemBuilder($feed, $spec, $source, new BuildDiagnostics());
		$rows = [];
		$previewedIds = [];

		foreach ($elements as $element) {
			foreach ($source->items($element) as $suppliedItem) {
				$item = $itemBuilder->forElement($element, $suppliedItem);
				$missing = $itemBuilder->missingRequired($item);
				$itemId = $item['id'] ?? null;
				$isDuplicate = $missing === null && is_string($itemId) && isset($previewedIds[$itemId]);
				// Only a published item claims its id, as in the build.
				if ($missing === null && is_string($itemId)) {
					$previewedIds[$itemId] = true;
				}

				$rows[] = [
					'elementId' => (int) $element->id,
					'item' => $itemBuilder->renameForDocument($item),
					'missing' => $missing === null ? null : $spec->documentName($missing),
					'duplicate' => $isDuplicate,
				];
			}
		}

		return array_slice($rows, 0, $limit);
	}

	/**
	 * Required attributes with neither a mapping nor a default value.
	 *
	 * @return string[]
	 */
	public function unmappedRequiredAttributes(Feed $feed, FeedSpec $spec, FeedSource $source): array
	{
		$unmapped = [];

		foreach ($source->mappableAttributes($spec) as $name => $attributeDefinition) {
			if (! $attributeDefinition->required) {
				continue;
			}

			$mappingSource = $feed->mappingSource($name, $spec);

			if (Mapping::parse($mappingSource)['kind'] === Mapping::NO_INCLUDE) {
				$unmapped[] = $name;
				continue;
			}

			if ($mappingSource === Mapping::USE_DEFAULT && $feed->mappingDefault($name) === '') {
				$unmapped[] = $name;
			}

			if ($mappingSource === Mapping::TWIG && $feed->mappingTwig($name) === '') {
				$unmapped[] = $name;
			}
		}

		return $unmapped;
	}

	/**
	 * Fetches the image the feed's first item would publish, resolved exactly as a build resolves it.
	 *
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function testImage(Feed $feed): ImageTestResult
	{
		$spec = $feed->getSpec();
		$minimumSize = $spec->minimumImageSize();
		$imageAttribute = $spec->imageAttribute();

		$source = FeedSource::forFeed($feed);
		$element = $source->query()->limit(1)->one();
		$attributeDefinition = $imageAttribute === null ? null : ($spec->attributes()[$imageAttribute] ?? null);

		if (! $element instanceof ElementInterface || ! $attributeDefinition instanceof AttributeDefinition) {
			return ImageTestResult::failed($minimumSize, Craft::t(ProductFeeds::HANDLE, 'imageTest.noProduct'));
		}

		$transformCoverage = $this->craftTransformCoverage($feed, $source, $attributeDefinition->name);

		$source->prepareBatch([$element]);
		$itemBuilder = new ItemBuilder($feed, $spec, $source, new BuildDiagnostics());
		$imageValue = $itemBuilder->forElement($element, $source->items($element)[0] ?? new SuppliedItem())[$attributeDefinition->name] ?? null;
		$url = is_array($imageValue) ? ($imageValue[0] ?? null) : $imageValue;
		if ($url === null || $url === '') {
			return ImageTestResult::failed($minimumSize, Craft::t(ProductFeeds::HANDLE, 'imageTest.noUrl'), transformCoverage: $transformCoverage);
		}

		try {
			$response = Craft::createGuzzleClient([
				'timeout' => 10,
			])->get($url, [
				'http_errors' => false,
			]);
		} catch (Throwable $throwable) {
			return ImageTestResult::failed($minimumSize, $throwable->getMessage(), $url, $transformCoverage);
		}

		$size = @getimagesizefromstring((string) $response->getBody());

		return ImageTestResult::fetched(
			$minimumSize,
			$url,
			$response->getStatusCode(),
			$response->getHeaderLine('Content-Type') ?: null,
			is_array($size) ? $size[0] : null,
			is_array($size) ? $size[1] : null,
			$transformCoverage,
		);
	}

	/**
	 * How many of the feed's images already exist at its Craft transform, out of how many it publishes.
	 * Null unless the feed maps the image to an Assets field and resizes it with Craft.
	 *
	 * Reads Craft's transform index rather than the filesystem, so it stays fast on a large catalog.
	 *
	 * @return array{generated: int, total: int}|null
	 * @throws InvalidConfigException
	 */
	private function craftTransformCoverage(Feed $feed, FeedSource $source, string $imageAttribute): ?array
	{
		$imageTransform = ImageTransform::fromFeed($feed);
		if ($imageTransform->imageEngine !== ImageEngine::Craft || (! $imageTransform->hasNamedTransform() && ! $imageTransform->hasSize())) {
			return null;
		}

		$mapping = Mapping::parse($feed->mappingSource($imageAttribute, $feed->getSpec()));
		if ($mapping['kind'] !== Mapping::FIELD && $mapping['kind'] !== Mapping::PRODUCT_FIELD) {
			return null;
		}

		$fieldId = null;
		foreach ($source->fieldLayouts()[$mapping['kind']] ?? [] as $fieldLayout) {
			$fieldId ??= $fieldLayout->getFieldByHandle($mapping['value'])?->id;
		}

		if ($fieldId === null) {
			return null;
		}

		$elementIds = $source->query()->ids();
		$ownerIds = $mapping['kind'] === Mapping::PRODUCT_FIELD
			? (new Query())
				->select(['primaryOwnerId'])
				->distinct()
				->from([CommerceTable::VARIANTS])
				->where([
					'id' => $elementIds,
				])
				->column()
			: $elementIds;

		// Each element's first image, which is the one the feed publishes as its main image
		$assetIds = (new Query())
			->select(['targetId'])
			->distinct()
			->from([Table::RELATIONS])
			->where([
				'fieldId' => $fieldId,
				'sourceId' => $ownerIds,
				'sortOrder' => 1,
			])
			->andWhere([
				'or',
				[
					'sourceSiteId' => null,
				],
				[
					'sourceSiteId' => $feed->siteId,
				],
			])
			->column();

		$craftTransform = ImageTransforms::normalizeTransform(
			$imageTransform->hasNamedTransform() ? $imageTransform->namedTransform : $imageTransform->toConfig()
		);
		if (! $craftTransform instanceof CraftImageTransform) {
			return null;
		}

		$generated = (new Query())
			->from([Table::IMAGETRANSFORMINDEX])
			->where([
				'assetId' => $assetIds,
				'transformString' => ImageTransforms::getTransformString($craftTransform),
				'format' => $craftTransform->format,
				'fileExists' => true,
			])
			->count('DISTINCT [[assetId]]');

		return [
			'generated' => (int) $generated,
			'total' => count($assetIds),
		];
	}

	/**
	 * Configuration errors, all of them permanent, so the queue job must not retry them.
	 *
	 * @throws FeedBuildException
	 * @throws InvalidConfigException
	 */
	private function assertBuildable(Feed $feed, FeedSpec $spec, FeedSource $source): void
	{
		if ($source instanceof MissingSource) {
			throw new FeedBuildException($source->errorMessage());
		}

		if (! $feed->getStore() instanceof Store) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.noStoreForSite'));
		}

		// Fail the build when the engine's plugin is uninstalled, since every image would resolve to null and
		// every item would be excluded as a blank `image_link`.
		$imageEngine = ImageEngine::from($feed->imageEngine);
		if (! $imageEngine->isAvailable()) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.imageEngineUnavailable', [
				'engine' => $imageEngine->label(),
			]));
		}

		$withoutUrls = $source->sourcesWithoutUrls();
		if ($withoutUrls !== []) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.sourcesWithoutUrls', [
				'names' => implode(', ', $withoutUrls),
			]));
		}

		if ($source->effectiveSourceIds() === [] && ! $source instanceof CustomSource) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.noSourcesWithUrls'));
		}

		$unmapped = $this->unmappedRequiredAttributes($feed, $spec, $source);
		if ($unmapped !== []) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.requiredAttributesUnmapped', [
				'attributes' => implode(', ', $unmapped),
			]));
		}
	}

	/**
	 * Streams the catalog into the writer, listing the items it has to skip in the excluded report.
	 *
	 * @param (callable(int, int): void)|null $onProgress
	 * @return int the number of items written
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function writeItems(
		FeedSource $source,
		FeedWriter $writer,
		ExcludedReport $report,
		ItemBuilder $itemBuilder,
		?callable $onProgress,
	): int {
		$query = $source->query();
		// ElementQuery::count() returns whatever type the PDO driver gave it, string included.
		$total = (int) $query->count();
		$batchSize = ProductFeeds::plugin()->getSettings()->batchSize;
		$itemCount = 0;
		$elementCount = 0;
		$publishedIds = [];

		$writer->open();

		try {
			foreach ($query->batch($batchSize) as $batch) {
				/** @var ElementInterface[] $batch */
				$source->prepareBatch($batch);

				foreach ($batch as $element) {
					$elementItems = $this->itemsForElement($source, $itemBuilder, $element, $publishedIds);

					foreach ($elementItems['items'] as $item) {
						$writer->writeItem($item);
						$itemCount++;
					}

					foreach ($elementItems['excluded'] as $reportRow) {
						$report->write($reportRow);
					}
				}

				$writer->flush();
				$elementCount += count($batch);

				if ($onProgress !== null) {
					$onProgress($elementCount, $total);
				}
			}

			$writer->close();
			$report->close();
		} catch (Throwable $throwable) {
			$writer->abort();
			$report->abort();

			throw $throwable;
		}

		return $itemCount;
	}

	/**
	 * Builds an element's items, setting aside the ones the feed has to exclude.
	 *
	 * @param array<array-key, true> $publishedIds ids already in the feed, which this adds to
	 * @return array{items: list<array<string, string|list<string>>>, excluded: list<array{id: string, title: string, cpUrl: string, issue: string}>}
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function itemsForElement(FeedSource $source, ItemBuilder $itemBuilder, ElementInterface $element, array &$publishedIds): array
	{
		$items = [];
		$excluded = [];

		foreach ($source->items($element) as $suppliedItem) {
			$item = $itemBuilder->forElement($element, $suppliedItem);
			$missing = $itemBuilder->missingRequired($item);

			if ($missing !== null) {
				$itemBuilder->recordSkip($element, $missing);
				$excluded[] = $this->reportRow($source, $element, $item, $missing);

				continue;
			}

			$itemId = $item['id'] ?? null;
			if (is_string($itemId)) {
				if (isset($publishedIds[$itemId])) {
					$itemBuilder->recordSkip($element, BuildDiagnostics::DUPLICATE_ID);
					$excluded[] = $this->reportRow($source, $element, $item, Craft::t(ProductFeeds::HANDLE, 'mapping.excludedDuplicateId'));

					continue;
				}

				$publishedIds[$itemId] = true;
			}

			$items[] = $itemBuilder->renameForDocument($item);
		}

		return [
			'items' => $items,
			'excluded' => $excluded,
		];
	}

	/**
	 * @throws Exception
	 * @throws FeedBuildException
	 * @throws FsException
	 */
	private function publish(
		FsInterface $fs,
		Feed $feed,
		string $tempPath,
		string $reportPath,
		int $itemCount,
		BuildDiagnostics $diagnostics,
	): BuildResult {
		$bytes = (int) filesize($tempPath);
		$bytesUncompressed = Gzip::uncompressedSize($tempPath);

		try {
			// `FeedController` sends the row's byte count as the response length, and the row is not
			// updated until this result is recorded.
			$this->publishReport($fs, $feed, $reportPath, $diagnostics->skippedCount() > 0);
			$this->publishFile($fs, $tempPath, $feed->getPath());
		} finally {
			// `publishFile()` only clears the temp file it was handed, so a throw leaves the report's own.
			FileHelper::unlink($reportPath);
		}

		return new BuildResult($itemCount, $bytes, $bytesUncompressed, $diagnostics);
	}

	private function queuedBuildDirectory(Feed $feed, string $buildId): string
	{
		return sprintf('%s/%s', $feed->getQueuedBuildsPath(), $buildId);
	}

	private function statePath(Feed $feed, string $buildId): string
	{
		return sprintf('%s/state.json', $this->queuedBuildDirectory($feed, $buildId));
	}

	private function batchPath(Feed $feed, string $buildId, int $batchIndex): string
	{
		return sprintf('%s/%d.json', $this->queuedBuildDirectory($feed, $buildId), $batchIndex);
	}

	/**
	 * @throws FsException
	 */
	private function queuedBuildState(FsInterface $fs, Feed $feed, string $buildId): QueuedBuildState
	{
		return QueuedBuildState::fromJson($fs->read($this->statePath($feed, $buildId)));
	}

	/**
	 * Deletes the saved batches of builds that started longer ago than the build timeout, which a worker
	 * stopped before they finished.
	 *
	 * @throws FsException
	 * @throws InvalidConfigException
	 */
	private function deleteExpiredQueuedBuilds(FsInterface $fs, Feed $feed): void
	{
		foreach ($this->queuedBuildIds($fs, $feed, expired: true) as $buildId) {
			$fs->deleteDirectory($this->queuedBuildDirectory($feed, $buildId));
		}
	}

	/**
	 * A build ID starts with the build's start time, so its age is read from its name.
	 *
	 * @return list<string>
	 * @throws FsException
	 * @throws InvalidConfigException
	 */
	private function queuedBuildIds(FsInterface $fs, Feed $feed, bool $expired): array
	{
		$directory = $feed->getQueuedBuildsPath();
		if (! $fs->directoryExists($directory)) {
			return [];
		}

		$cutoff = time() - ProductFeeds::plugin()->getSettings()->buildTimeout;
		$buildIds = [];

		/** @var FsListing $listing */
		foreach ($fs->getFileList($directory, false) as $listing) {
			if (! $listing->getIsDir()) {
				continue;
			}

			$buildId = $listing->getBasename();
			$startedAt = (int) strtok($buildId, '-');

			if (($startedAt < $cutoff) === $expired) {
				$buildIds[] = $buildId;
			}
		}

		return $buildIds;
	}

	/**
	 * Report an item by its own id, since a custom source can return several items per element.
	 *
	 * @param array<string, string|list<string>> $item
	 * @return array{id: string, title: string, cpUrl: string, issue: string}
	 */
	private function reportRow(FeedSource $source, ElementInterface $element, array $item, string $issue): array
	{
		$row = $source->reportRow($element, $issue);

		if (is_string($item['id'] ?? null)) {
			$row['id'] = $item['id'];
		}

		return $row;
	}

	/**
	 * Advisory, and never fails a build: a queue worker frequently cannot resolve its own site's public
	 * hostname.
	 */
	private function checkFeedUrl(string $url): UrlCheck
	{
		try {
			$response = Craft::createGuzzleClient([
				'timeout' => 5,
			])->head($url, [
				'http_errors' => false,
			]);

			return new UrlCheck(
				$response->getStatusCode(),
				$response->getHeaderLine('Content-Type') ?: null,
			);
		} catch (Throwable $throwable) {
			return new UrlCheck(error: $throwable->getMessage());
		}
	}

	/**
	 * Writes the feed's build status to its row, whichever way the build ends.
	 *
	 * @param (callable(int, int): void)|null $onProgress
	 * @throws FeedBuildException
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function buildAndRecord(Feed $feed, ?callable $onProgress = null): BuildResult
	{
		$feeds = $this->feeds();

		$startedAt = new DateTime();
		$feeds->recordBuild($feed, BuildStatus::Building, $startedAt);

		try {
			$result = $this->build($feed, $onProgress);
		} catch (Throwable $throwable) {
			// Log the trace, since the feed row stores only the message
			Craft::error(sprintf('Building the “%s” product feed failed: %s', $feed->handle, $throwable), ProductFeeds::HANDLE);
			$feeds->recordBuild($feed, BuildStatus::Failed, $startedAt, error: $throwable->getMessage());

			throw $throwable;
		}

		// The route 404s until the feed row carries the artifact's size.
		$feeds->recordBuild($feed, BuildStatus::Ok, $startedAt, $result);
		$feeds->recordUrlCheck($feed, $this->checkFeedUrl($feeds->getFeedUrl($feed)));

		return $result;
	}

	/**
	 * @throws Exception
	 * @throws FeedBuildException
	 * @throws FsException
	 */
	private function publishReport(FsInterface $fs, Feed $feed, string $tempPath, bool $hasExclusions): void
	{
		$path = $feed->getExcludedReportPath();

		if (! $hasExclusions) {
			FileHelper::unlink($tempPath);
			if ($fs->fileExists($path)) {
				$fs->deleteFile($path);
			}

			return;
		}

		$this->publishFile($fs, $tempPath, $path);
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function feeds(): Feeds
	{
		return ProductFeeds::plugin()->getFeeds();
	}

	/**
	 * Writes to a staging name and renames it over the live file, so a fetch mid-publish gets the previous
	 * feed rather than a partial one. The swap is confirmed by size, because `Local::renameFile()`
	 * swallows a failed rename and an existence check would still pass on the old artifact.
	 *
	 * @throws FeedBuildException
	 * @throws FsException
	 * @throws Exception on a failed swap, which is transient and has to stay retryable
	 */
	private function publishFile(FsInterface $fs, string $tempPath, string $path): void
	{
		$stagedPath = $path . '.tmp';
		$stagedBytes = (int) filesize($tempPath);

		$stream = fopen($tempPath, 'rb');
		if ($stream === false) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.builtFileUnreadable'));
		}

		try {
			$fs->writeFileFromStream($stagedPath, $stream);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}

			FileHelper::unlink($tempPath);
		}

		$fs->renameFile($stagedPath, $path);

		if (! $fs->fileExists($path) || $fs->getFileSize($path) !== $stagedBytes) {
			throw new Exception(Craft::t(ProductFeeds::HANDLE, 'error.publishFailed', [
				'path' => $path,
			]));
		}
	}
}
