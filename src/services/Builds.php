<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FsInterface;
use craft\commerce\models\Store;
use craft\errors\FsException;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use DateTime;
use fostercommerce\productfeeds\enums\BuildStatus;
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
use fostercommerce\productfeeds\models\UrlCheck;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class Builds extends Component
{
	/**
	 * Builds a feed under its lock. The entry point for anything that builds a live feed: the queue job and
	 * the console command.
	 *
	 * Standing down is the caller's to handle: `BuildFeed` comes back later, the console reports it.
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

		// This build reads the catalog as it stands now, so an edit landing from here on needs its own build.
		$buildQueue->clearPending($feedId);

		try {
			return $this->buildAndRecord($feed, $onProgress);
		} finally {
			$mutex->release($lockName);
		}
	}

	/**
	 * Streams the whole catalog into the artifact and publishes it. Takes no lock and records nothing:
	 * a live build goes through `buildUnderLock()`.
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
		$writer = $spec->writer($tempPath, $feed->name, UrlHelper::siteUrl('', null, null, $feed->siteId));
		$report = new ExcludedReport($reportPath);
		$itemBuilder = new ItemBuilder($feed, $spec, $source, $diagnostics);

		$itemCount = $this->writeItems($source, $writer, $report, $itemBuilder, $onProgress);

		$bytes = (int) filesize($tempPath);
		$bytesUncompressed = Gzip::uncompressedSize($tempPath);

		try {
			$this->publishFile($fs, $tempPath, $feed->getPath());
			$this->publishReport($fs, $feed, $reportPath, $diagnostics->skippedCount() > 0);
		} finally {
			// `publishFile()` only clears the temp file it was handed, so a throw before the report is
			// published strands the report's own.
			if (is_file($reportPath)) {
				FileHelper::unlink($reportPath);
			}
		}

		return new BuildResult($itemCount, $bytes, $bytesUncompressed, $diagnostics);
	}

	/**
	 * The first items a build would publish, with the reason it would skip each one it cannot. Blank
	 * attributes are dropped from an item, so without the reason a skipped item previews as complete.
	 *
	 * @return list<array{elementId: int, item: array<string, string|list<string>>, missing: ?string}>
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

		foreach ($elements as $element) {
			$item = $itemBuilder->forElement($element);
			$missing = $itemBuilder->missingRequired($item);

			$rows[] = [
				'elementId' => (int) $element->id,
				'item' => $itemBuilder->renameForDocument($item),
				'missing' => $missing === null ? null : $spec->documentName($missing),
			];
		}

		return $rows;
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
		}

		return $unmapped;
	}

	/**
	 * Fetches the image the first item of the feed would publish, so the admin can see what the platform
	 * will receive before a build runs.
	 *
	 * @throws InvalidConfigException
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

		$itemBuilder = new ItemBuilder($feed, $spec, $source, new BuildDiagnostics());
		$url = $itemBuilder->mappedValues($element, $attributeDefinition)[0] ?? null;
		if ($url === null || $url === '') {
			return ImageTestResult::failed($minimumSize, Craft::t(ProductFeeds::HANDLE, 'imageTest.noUrl'));
		}

		try {
			$response = Craft::createGuzzleClient([
				'timeout' => 10,
			])->get($url, [
				'http_errors' => false,
			]);
		} catch (Throwable $throwable) {
			return ImageTestResult::failed($minimumSize, $throwable->getMessage(), $url);
		}

		$size = @getimagesizefromstring((string) $response->getBody());

		return ImageTestResult::fetched(
			$minimumSize,
			$url,
			$response->getStatusCode(),
			$response->getHeaderLine('Content-Type') ?: null,
			is_array($size) ? $size[0] : null,
			is_array($size) ? $size[1] : null,
		);
	}

	/**
	 * Configuration errors, all of them permanent, so the queue job must not retry them.
	 *
	 * @throws FeedBuildException
	 * @throws InvalidConfigException
	 */
	private function assertBuildable(Feed $feed, FeedSpec $spec, FeedSource $source): void
	{
		if (! $feed->getStore() instanceof Store) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.noStoreForSite'));
		}

		$withoutUrls = $source->sourcesWithoutUrls();
		if ($withoutUrls !== []) {
			throw new FeedBuildException(Craft::t(ProductFeeds::HANDLE, 'error.sourcesWithoutUrls', [
				'names' => implode(', ', $withoutUrls),
			]));
		}

		if ($source->effectiveSourceIds() === []) {
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

		$writer->open();

		try {
			foreach ($query->batch($batchSize) as $batch) {
				/** @var ElementInterface[] $batch */
				$source->prepareBatch($batch);

				foreach ($batch as $element) {
					$item = $itemBuilder->forElement($element);
					$missing = $itemBuilder->missingRequired($item);

					if ($missing !== null) {
						$itemBuilder->recordSkip($element, $missing);
						$report->write($source->reportRow($element, $missing));

						continue;
					}

					$writer->writeItem($itemBuilder->renameForDocument($item));
					$itemCount++;
				}

				$writer->flush();

				if ($onProgress !== null) {
					$onProgress($itemCount, $total);
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
	 * Advisory only: a queue worker frequently cannot resolve its own site's public hostname, so this
	 * never fails a build.
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
