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
use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\enums\BuildStatus;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\feeds\AttributeDefinition;
use fostercommerce\productfeeds\feeds\ExcludedReport;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\helpers\FeedValue;
use fostercommerce\productfeeds\helpers\Gzip;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\BuildDiagnostics;
use fostercommerce\productfeeds\models\BuildResult;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\UrlCheck;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use Money\Currency;
use Money\Money;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class Builds extends Component
{
	/**
	 * @param (callable(int, int): void)|null $onProgress
	 * @throws FeedBuildException
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function buildAndRecord(Feed $feed, ?callable $onProgress = null): BuildResult
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

		$feeds->recordBuild($feed, BuildStatus::Ok, $startedAt, $result);
		$feeds->recordUrlCheck($feed, $this->checkFeedUrl($feeds->getFeedUrl($feed)));

		return $result;
	}

	/**
	 * @param (callable(int, int): void)|null $onProgress
	 * @throws FeedBuildException on a configuration error that retrying cannot fix
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function build(Feed $feed, ?callable $onProgress = null): BuildResult
	{
		$feeds = $this->feeds();

		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$source = FeedSource::forFeed($feed);
		$this->assertBuildable($feed, $spec, $source);

		// Resolved before an item is read: a missing filesystem is a configuration error, and finding it
		// out only at publish time would waste a whole pass over the catalog.
		$fs = $feeds->getFs();
		$tempPath = sprintf('%s/%s.%s.gz', Craft::$app->getPath()->getTempPath(), $feed->token, $spec->fileExtension());
		$writer = $spec->writer(
			$tempPath,
			$feed->name,
			UrlHelper::siteUrl('', null, null, $feed->siteId)
		);

		$itemCount = 0;
		$diagnostics = new BuildDiagnostics();

		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();

		$query = $source->query();
		// ElementQuery::count() returns whatever type the PDO driver gave it, string included.
		$total = (int) $query->count();
		$batchSize = $plugin->getSettings()->batchSize;

		$reportPath = sprintf('%s/%s-excluded.csv', Craft::$app->getPath()->getTempPath(), $feed->token);
		$report = new ExcludedReport($reportPath);

		$writer->open();

		try {
			foreach ($query->batch($batchSize) as $batch) {
				/** @var ElementInterface[] $batch */
				$source->prepareBatch($batch);

				foreach ($batch as $element) {
					$item = $this->buildItem($element, $feed, $spec, $source, $diagnostics);
					$missing = $this->missingRequired($item, $spec);

					if ($missing !== null) {
						$diagnostics->countSkipped($missing);
						$diagnostics->sampleSkipped((int) $element->id, $missing);
						$report->write($source->reportRow($element, $missing));

						continue;
					}

					$writer->writeItem($this->documentItem($item, $spec));
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
			$report->close();
			FileHelper::unlink($reportPath);

			throw $throwable;
		}

		$bytes = (int) filesize($tempPath);
		$bytesUncompressed = Gzip::uncompressedSize($tempPath);

		$this->publishFile($fs, $tempPath, $feed->getPath());
		$this->publishReport($fs, $feed, $reportPath, $diagnostics->skippedCount() > 0);

		return new BuildResult($itemCount, $bytes, $bytesUncompressed, $diagnostics);
	}

	/**
	 * The first items a build would publish, with the reason it would skip each one it cannot.
	 *
	 * A blank attribute is dropped from the item, so an item missing a required one would otherwise
	 * preview as complete.
	 *
	 * @return list<array{elementId: int, item: array<string, string|list<string>>, missing: ?string}>
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function preview(Feed $feed, int $limit = 10): array
	{
		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$source = FeedSource::forFeed($feed);

		/** @var ElementInterface[] $elements */
		$elements = $source->query()->limit($limit)->all();
		$source->prepareBatch($elements);

		// The preview shows the items, not the counts, so these are collected and dropped.
		$diagnostics = new BuildDiagnostics();
		$rows = [];

		foreach ($elements as $element) {
			$item = $this->buildItem($element, $feed, $spec, $source, $diagnostics);
			$missing = $this->missingRequired($item, $spec);

			$rows[] = [
				'elementId' => (int) $element->id,
				'item' => $this->documentItem($item, $spec),
				'missing' => $missing === null ? null : $spec->documentName($missing),
			];
		}

		return $rows;
	}

	/**
	 * Configuration errors, all of them permanent, so the queue job must not retry them.
	 *
	 * @throws FeedBuildException
	 * @throws InvalidConfigException
	 */
	public function assertBuildable(Feed $feed, FeedSpec $spec, FeedSource $source): void
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
	 * A missing `description` would skip every item, so the build refuses rather than publishing an
	 * empty feed.
	 *
	 * @return string[]
	 */
	public function unmappedRequiredAttributes(Feed $feed, FeedSpec $spec, FeedSource $source): array
	{
		$computed = $source->computedAttributes();
		$unmapped = [];

		foreach ($spec->attributes() as $name => $attributeDefinition) {
			if (! $attributeDefinition->required) {
				continue;
			}

			if (in_array($name, $computed, true)) {
				continue;
			}

			$mapping = $feed->fieldMapping[$name] ?? null;
			$source_ = $mapping['source'] ?? Mapping::NO_INCLUDE;
			$default = $mapping['default'] ?? '';

			if (Mapping::parse($source_)['kind'] === Mapping::NO_INCLUDE) {
				$unmapped[] = $name;
				continue;
			}

			if ($source_ === Mapping::USE_DEFAULT && $default === '') {
				$unmapped[] = $name;
			}
		}

		return $unmapped;
	}

	/**
	 * @return array{ok: bool, url: ?string, status: ?int, contentType: ?string, width: ?int, height: ?int, meetsMinimum: bool, minimumWidth: ?int, minimumHeight: ?int, error: ?string}
	 * @throws InvalidConfigException
	 */
	public function testImage(Feed $feed): array
	{
		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$minimum = $spec->minimumImageSize();
		$imageAttribute = $spec->imageAttribute();

		$blank = [
			'ok' => false,
			'url' => null,
			'status' => null,
			'contentType' => null,
			'width' => null,
			'height' => null,
			'meetsMinimum' => false,
			'minimumWidth' => $minimum[0] ?? null,
			'minimumHeight' => $minimum[1] ?? null,
			'error' => null,
		];

		$source = FeedSource::forFeed($feed);
		$element = $source->query()->limit(1)->one();
		$attributeDefinition = $imageAttribute === null ? null : ($spec->attributes()[$imageAttribute] ?? null);

		if (! $element instanceof ElementInterface || ! $attributeDefinition instanceof AttributeDefinition) {
			return [
				...$blank,
				'error' => Craft::t(ProductFeeds::HANDLE, 'imageTest.noProduct'),
			];
		}

		$url = $this->mappedValues($element, $feed, $source, $attributeDefinition)[0] ?? null;
		if ($url === null || $url === '') {
			return [
				...$blank,
				'error' => Craft::t(ProductFeeds::HANDLE, 'imageTest.noUrl'),
			];
		}

		try {
			$response = Craft::createGuzzleClient([
				'timeout' => 10,
			])->get($url, [
				'http_errors' => false,
			]);
		} catch (Throwable $throwable) {
			return [
				...$blank,
				'url' => $url,
				'error' => $throwable->getMessage(),
			];
		}

		$size = @getimagesizefromstring((string) $response->getBody());
		$width = is_array($size) ? $size[0] : null;
		$height = is_array($size) ? $size[1] : null;

		return [
			...$blank,
			'ok' => $response->getStatusCode() === 200 && $size !== false,
			'url' => $url,
			'status' => $response->getStatusCode(),
			'contentType' => $response->getHeaderLine('Content-Type') ?: null,
			'width' => $width,
			'height' => $height,
			'meetsMinimum' => $minimum === null
				|| ($width !== null && $height !== null && $width >= $minimum[0] && $height >= $minimum[1]),
		];
	}

	/**
	 * Advisory only. Never fails a build: a queue worker frequently cannot resolve its own site's
	 * public hostname.
	 *
	 * Call this only once the build is recorded. The route answers 404 until the feed row carries the
	 * artifact's size.
	 */
	public function checkFeedUrl(string $url): UrlCheck
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
	 * @return array<string, string|list<string>>
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	private function buildItem(
		ElementInterface $element,
		Feed $feed,
		FeedSpec $spec,
		FeedSource $source,
		BuildDiagnostics $diagnostics,
	): array {
		$computed = $source->computedAttributes();
		$derived = $spec->derivedAttributes();
		$imageAttribute = $spec->imageAttribute();
		$galleryAttribute = $spec->galleryAttribute();

		$item = [];
		$imageValues = [];

		foreach ($spec->attributes() as $name => $attributeDefinition) {
			if (in_array($name, $derived, true)) {
				continue;
			}

			$isComputed = in_array($name, $computed, true);
			$values = $isComputed
				? $this->asList($source->compute($element, $name))
				: $this->mappedValues($element, $feed, $source, $attributeDefinition);

			if ($attributeDefinition->attributeKind === AttributeKind::Money) {
				$values = $this->asMoney($values, $feed, $spec, $name, $diagnostics);
			}

			if ($name === $galleryAttribute) {
				$gallery = $this->galleryImages($feed, $spec, $name, $values, $imageValues);
				if ($gallery !== []) {
					$item[$name] = $gallery;
				}

				continue;
			}

			if ($values === []) {
				// A computed `sale_price` is null on every item not on promotion, and "Don't include" is blank
				// on all of them, so only a mapped attribute's blanks mean anything.
				if (! $isComputed && $this->isMapped($feed, $name)) {
					$diagnostics->countBlank($name);
				}

				continue;
			}

			if ($name === $imageAttribute) {
				$imageValues = $values;
			}

			$item[$name] = $values[0];
		}

		return $spec->finalizeItem($item);
	}

	/**
	 * @return list<string>
	 * @throws InvalidConfigException
	 */
	private function mappedValues(
		ElementInterface $element,
		Feed $feed,
		FeedSource $source,
		AttributeDefinition $attributeDefinition,
	): array {
		$mapping = $feed->fieldMapping[$attributeDefinition->name] ?? null;
		$spec = $mapping['source'] ?? Mapping::NO_INCLUDE;
		$default = trim($mapping['default'] ?? '');

		$parsed = Mapping::parse($spec);
		if (in_array($parsed['kind'], [Mapping::NO_INCLUDE, Mapping::IMAGE_OVERFLOW], true)) {
			return [];
		}

		$values = $parsed['kind'] === Mapping::USE_DEFAULT
			? []
			: $source->resolve($element, $spec, $attributeDefinition);

		if ($values === [] && $default !== '') {
			$values = $attributeDefinition->attributeKind === AttributeKind::Image
				? $source->defaultImageUrl($default)
				: [$default];
		}

		$kind = $attributeDefinition->attributeKind;
		if ($kind === AttributeKind::Url || $kind === AttributeKind::Image) {
			return array_values(array_filter($values, static fn (string $url): bool => UrlHelper::isAbsoluteUrl($url)));
		}

		return $values;
	}

	/**
	 * @param list<string> $mappedValues resolved from the attribute's own source, empty for overflow
	 * @param list<string> $imageValues the main image source's full list
	 * @return list<string>
	 */
	private function galleryImages(Feed $feed, FeedSpec $spec, string $gallery, array $mappedValues, array $imageValues): array
	{
		$source = $feed->fieldMapping[$gallery]['source'] ?? Mapping::IMAGE_OVERFLOW;
		$limit = $spec->maxGalleryImages();

		return match (Mapping::parse($source)['kind']) {
			Mapping::NO_INCLUDE => [],
			Mapping::IMAGE_OVERFLOW => array_slice($imageValues, 1, $limit),
			default => array_slice($mappedValues, 0, $limit),
		};
	}

	private function isMapped(Feed $feed, string $attribute): bool
	{
		$source = $feed->fieldMapping[$attribute]['source'] ?? Mapping::NO_INCLUDE;

		return Mapping::parse($source)['kind'] !== Mapping::NO_INCLUDE;
	}

	/**
	 * @param list<string> $values bare decimals
	 * @return list<string>
	 */
	private function asMoney(array $values, Feed $feed, FeedSpec $spec, string $attribute, BuildDiagnostics $diagnostics): array
	{
		$currency = $feed->getCurrency();
		if (! $currency instanceof Currency) {
			return [];
		}

		$formatted = [];

		foreach ($values as $value) {
			$money = FeedValue::moneyFromDecimal($value, $currency);
			if (! $money instanceof Money) {
				continue;
			}

			if (! $money->isPositive()) {
				$diagnostics->countInvalid($attribute);
			}

			$formatted[] = $spec->formatMoney($money);
		}

		return $formatted;
	}

	/**
	 * @param string|list<string>|null $value
	 * @return list<string>
	 */
	private function asList(string|array|null $value): array
	{
		if ($value === null) {
			return [];
		}

		$values = is_array($value) ? $value : [$value];

		return array_values(array_filter($values, static fn (string $item): bool => $item !== ''));
	}

	/**
	 * Renames the attributes the platform spells its own way, once the required check has run against
	 * the shared names.
	 *
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|list<string>>
	 */
	private function documentItem(array $item, FeedSpec $spec): array
	{
		$document = [];

		foreach ($item as $attribute => $value) {
			$document[$spec->documentName($attribute)] = $value;
		}

		return $document;
	}

	/**
	 * @param array<string, string|list<string>> $item
	 */
	private function missingRequired(array $item, FeedSpec $spec): ?string
	{
		foreach ($spec->requiredAttributes() as $attribute) {
			if (($item[$attribute] ?? '') === '') {
				return $attribute;
			}
		}

		return null;
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
		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();

		return $plugin->getFeeds();
	}

	/**
	 * Writes the file under a staging name and renames it over the live one, so a fetch mid-publish gets
	 * the previous file rather than a partial one or a 404.
	 *
	 * `Local::renameFile()` swallows a failed rename with `@rename()` and returns void, so the swap is
	 * confirmed by size: the previous artifact would still pass an existence check.
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
