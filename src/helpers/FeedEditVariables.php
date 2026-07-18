<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\models\Site;
use fostercommerce\productfeeds\enums\AttributeKind;
use fostercommerce\productfeeds\enums\ImageEngine;
use fostercommerce\productfeeds\enums\ImageFit;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\enums\Source;
use fostercommerce\productfeeds\enums\StandardAttribute;
use fostercommerce\productfeeds\feeds\AttributeDefinition;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\UrlCheck;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * The variables the feed edit screen renders from.
 */
final class FeedEditVariables
{
	/**
	 * @return array<string, mixed>
	 * @throws Exception
	 * @throws InvalidConfigException
	 */
	public static function forFeed(Feed $feed, Site $site): array
	{
		$plugin = ProductFeeds::plugin();
		$spec = $feed->getSpec();
		$source = FeedSource::forFeed($feed);

		$urlCheck = $feed->lastBuildDiagnostics->urlCheck;
		$reachable = $urlCheck?->status === 200;

		return [
			'feed' => $feed,
			'site' => $site,
			'spec' => $spec,
			'mappingRows' => self::mappingRows($feed, $spec, $source),
			'unmappedRequired' => $plugin->getBuilds()->unmappedRequiredAttributes($feed, $spec, $source),
			'sourcesWithoutUrls' => $source->sourcesWithoutUrls(),
			'selectableSourceGroups' => $source->selectableSourceGroups(),
			'feedUrl' => $feed->id === null ? null : $plugin->getFeeds()->getFeedUrl($feed),
			'feedUrlTip' => $reachable
				? Craft::t(ProductFeeds::HANDLE, 'feed.urlReachable', [
					'contentType' => $urlCheck?->contentType ?? '?',
				])
				: null,
			'feedUrlWarning' => $urlCheck instanceof UrlCheck && ! $reachable
				? Craft::t(ProductFeeds::HANDLE, 'feed.urlUnreachable', [
					'detail' => $urlCheck->error ?? $urlCheck->status,
				])
				: null,
			'excludedProducts' => self::excludedProducts($feed, $source),
			'defaultImage' => self::defaultImage($feed, $spec),
			'filterCondition' => FilterCondition::builder($feed, $source),
			'platformOptions' => self::enumOptions(Platform::cases()),
			'sourceOptions' => self::enumOptions(Source::cases()),
			'imageFitOptions' => self::enumOptions(ImageFit::cases()),
			'imageEngineOptions' => self::imageEngineOptions(),
			'craftTransformOptions' => self::craftTransformOptions(),
			'canEdit' => Craft::$app->getUser()->checkPermission(ProductFeeds::PERMISSION_EDIT),
			'canBuild' => Craft::$app->getUser()->checkPermission(ProductFeeds::PERMISSION_BUILD),
		];
	}

	/**
	 * The variables the preview panel renders from.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public static function forPreview(Feed $feed): array
	{
		$spec = $feed->getSpec();
		$imageAttributes = array_values(array_filter([$spec->imageAttribute(), $spec->galleryAttribute()]));

		return [
			'rows' => ProductFeeds::plugin()->getBuilds()->preview($feed),
			'attributeNames' => array_map($spec->documentName(...), array_keys($spec->attributes())),
			'imageAttributes' => array_map($spec->documentName(...), $imageAttributes),
			'idAttribute' => $spec->documentName(StandardAttribute::Id->value),
			'platformLabel' => $feed->getPlatform()->label(),
		];
	}

	/**
	 * One row per attribute the admin can map.
	 *
	 * @return list<array<string, mixed>>
	 * @throws InvalidConfigException
	 */
	private static function mappingRows(Feed $feed, FeedSpec $spec, FeedSource $source): array
	{
		$options = MappingOptions::forSource($source, $spec);
		$diagnostics = $feed->lastBuildDiagnostics;
		$galleryAttribute = $spec->galleryAttribute();
		$imageAttribute = $spec->imageAttribute();
		$rows = [];

		foreach ($source->mappableAttributes($spec) as $name => $attributeDefinition) {
			$mappingSource = $feed->mappingSource($name, $spec);
			$blanks = $diagnostics->blankByAttribute[$name] ?? null;
			$invalid = $diagnostics->invalidByAttribute[$name] ?? null;
			// An image only drops on an unparseable site base URL; a URL value drops on the value itself.
			$relativeUrls = $diagnostics->relativeUrlByAttribute[$name] ?? null;
			$relativeUrlMessage = $relativeUrls === null ? null : Craft::t(
				ProductFeeds::HANDLE,
				$attributeDefinition->attributeKind === AttributeKind::Image
					? 'mapping.unresolvedImageUrls'
					: 'mapping.relativeUrls',
				[
					'n' => $relativeUrls,
				]
			);

			// Only an attribute the feed carries, with nothing reported against it, was set on every item. The
			// gallery is excluded: a blank one is normal.
			$setOnAllItems = null;
			if ($blanks === null && $invalid === null && $relativeUrls === null && $mappingSource !== Mapping::NO_INCLUDE && $name !== $galleryAttribute) {
				$setOnAllItems = $feed->lastBuildItemCount;
			}

			$rows[] = [
				'name' => $name,
				'required' => $attributeDefinition->required,
				'note' => $attributeDefinition->note,
				'docUrl' => $spec->docUrl($name),
				'sourceOptions' => $options[$name] ?? [],
				'source' => $mappingSource,
				'default' => $feed->mappingDefault($name),
				'defaultKind' => self::defaultKind($name, $attributeDefinition, $galleryAttribute, $imageAttribute),
				'defaultOptions' => self::defaultOptions($attributeDefinition),
				'blanks' => $blanks,
				'invalid' => $invalid,
				'relativeUrls' => $relativeUrls,
				'relativeUrlMessage' => $relativeUrlMessage,
				'relativeUrlSample' => $diagnostics->sampleRelativeUrls[$name] ?? null,
				'setOnAllItems' => $setOnAllItems,
			];
		}

		return $rows;
	}

	/**
	 * Which control the default cell shows.
	 */
	private static function defaultKind(
		string $name,
		AttributeDefinition $attributeDefinition,
		?string $galleryAttribute,
		?string $imageAttribute,
	): string {
		return match (true) {
			// The gallery takes its images from the main image's overflow or from its own field, so a default
			// would never be read.
			$name === $galleryAttribute => 'none',
			$name === $imageAttribute => 'image',
			$attributeDefinition->values !== [] => 'select',
			default => 'text',
		};
	}

	/**
	 * The values the platform accepts, with a blank so the admin can choose none.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	private static function defaultOptions(AttributeDefinition $attributeDefinition): array
	{
		if ($attributeDefinition->values === []) {
			return [];
		}

		$options = [[
			'label' => '',
			'value' => '',
		]];

		foreach ($attributeDefinition->values as $value) {
			$options[] = [
				'label' => $value,
				'value' => $value,
			];
		}

		return $options;
	}

	/**
	 * Only the engines whose plugin is installed.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	private static function imageEngineOptions(): array
	{
		$options = [];

		foreach (ImageEngine::cases() as $engine) {
			if ($engine->isAvailable()) {
				$options[] = [
					'label' => $engine->label(),
					'value' => $engine->value,
				];
			}
		}

		return $options;
	}

	/**
	 * The site's named transforms, with a blank so the admin can give a size instead.
	 *
	 * @return list<array{label: string, value: string}>
	 */
	private static function craftTransformOptions(): array
	{
		$options = [[
			'label' => Craft::t(ProductFeeds::HANDLE, 'imageTransform.customSize'),
			'value' => '',
		]];

		$transforms = Craft::$app->getImageTransforms()->getAllTransforms();

		foreach ($transforms as $transform) {
			$options[] = [
				'label' => (string) $transform->name,
				'value' => (string) $transform->handle,
			];
		}

		return $options;
	}

	/**
	 * @param list<ImageFit|Platform|Source> $cases
	 * @return list<array{label: string, value: string}>
	 */
	private static function enumOptions(array $cases): array
	{
		return array_map(
			static fn (ImageFit|Platform|Source $case): array => [
				'label' => $case->label(),
				'value' => $case->value,
			],
			$cases
		);
	}

	private static function defaultImage(Feed $feed, FeedSpec $spec): ?Asset
	{
		$imageAttribute = $spec->imageAttribute();
		$assetId = $imageAttribute === null ? '' : $feed->mappingDefault($imageAttribute);

		return is_numeric($assetId)
			? Craft::$app->getAssets()->getAssetById((int) $assetId)
			: null;
	}

	/**
	 * A capped sample of what the last build skipped, not the whole set. `FeedsController::actionExcludedCsv()`
	 * serves the full list.
	 *
	 * @return list<array{element: ElementInterface, reason: string}>
	 */
	private static function excludedProducts(Feed $feed, FeedSource $source): array
	{
		$elementType = $source->elementType();
		$elements = Craft::$app->getElements();
		$resolved = [];

		foreach ($feed->lastBuildDiagnostics->sampleSkipped as $skipped) {
			$element = $elements->getElementById($skipped['id'], $elementType, $feed->siteId);
			if ($element instanceof ElementInterface) {
				$resolved[] = [
					'element' => $element,
					'reason' => $skipped['reason'],
				];
			}
		}

		return $resolved;
	}
}
