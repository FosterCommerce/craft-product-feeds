<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementCondition;
use craft\errors\FsException;
use craft\helpers\Component;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\models\Site;
use craft\web\Controller;
use craft\web\View;
use fostercommerce\productfeeds\assetbundles\FeedEditAsset;
use fostercommerce\productfeeds\assetbundles\FeedIndexAsset;
use fostercommerce\productfeeds\enums\ImageEngine;
use fostercommerce\productfeeds\enums\ImageFit;
use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\enums\Source;
use fostercommerce\productfeeds\enums\StandardAttribute;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\helpers\ImageUrl;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\helpers\MappingOptions;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FeedsController extends Controller
{
	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 */
	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();
		$this->requirePermission(ProductFeeds::PERMISSION_VIEW);

		return true;
	}

	/**
	 * @throws Exception
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 */
	public function actionIndex(): Response
	{
		$plugin = $this->plugin();
		$feeds = $plugin->getFeeds();
		$this->getView()->registerAssetBundle(FeedIndexAsset::class);

		$site = $this->requestedSite();
		$siteFeeds = $feeds->getFeedsBySiteId((int) $site->id);

		$statusLabels = [];
		$feedUrls = [];

		foreach ($siteFeeds as $siteFeed) {
			$statusLabels[(int) $siteFeed->id] = Cp::componentStatusLabelHtml($siteFeed);
			$feedUrls[(int) $siteFeed->id] = $feeds->getFeedUrl($siteFeed);
		}

		return $this->renderTemplate('product-feeds/index', [
			'feeds' => $siteFeeds,
			'site' => $site,
			'statusLabels' => $statusLabels,
			'feedUrls' => $feedUrls,
			'canEdit' => Craft::$app->getUser()->checkPermission(ProductFeeds::PERMISSION_EDIT),
			'canBuild' => Craft::$app->getUser()->checkPermission(ProductFeeds::PERMISSION_BUILD),
			'fsConfigured' => $plugin->getSettings()->fsHandle !== null,
		]);
	}

	/**
	 * @throws Exception
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 */
	public function actionEdit(?int $feedId = null, ?Feed $feed = null): Response
	{
		$plugin = $this->plugin();
		$this->getView()->registerAssetBundle(FeedEditAsset::class);

		$feed ??= $feedId === null
			? new Feed([
				'siteId' => $this->requestedSite()->id,
			])
			: $plugin->getFeeds()->getFeedById($feedId);

		if (! $feed instanceof Feed) {
			throw new NotFoundHttpException();
		}

		$this->requireSiteAccess($feed);

		return $this->renderTemplate('product-feeds/_edit', $this->editVariables($feed));
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws Exception
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(ProductFeeds::PERMISSION_EDIT);

		$request = $this->request;
		$feedId = $request->getBodyParam('feedId');
		$plugin = $this->plugin();

		$feed = $feedId === null
			? new Feed([
				'siteId' => $this->requestedSite()->id,
			])
			: $plugin->getFeeds()->getFeedById($this->toInt($feedId)) ?? throw new NotFoundHttpException();

		$this->requireSiteAccess($feed);

		$feed->name = $this->toString($request->getBodyParam('name', ''));
		$feed->handle = $this->toString($request->getBodyParam('handle', ''));
		$feed->platform = $this->toString($request->getBodyParam('platform', Platform::Google->value));
		$feed->source = $this->toString($request->getBodyParam('source', Source::Variants->value));
		$feed->enabled = (bool) $request->getBodyParam('enabled', true);
		$feed->sourceIds = $this->toStringList($request->getBodyParam('sourceIds'));
		$feed->fieldMapping = Mapping::rows($request->getBodyParam('fieldMapping'));
		$feed->filterCondition = $this->postedFilterCondition($feed, $request->getBodyParam('filterCondition'));
		$this->applyImageSettings($feed);

		$withoutUrls = FeedSource::forFeed($feed)->sourcesWithoutUrls();
		if ($withoutUrls !== []) {
			$feed->addError('sourceIds', Craft::t(ProductFeeds::HANDLE, 'error.sourcesWithoutUrls', [
				'names' => implode(', ', $withoutUrls),
			]));
		}

		if ($feed->hasErrors() || ! $plugin->getFeeds()->saveFeed($feed)) {
			$this->setFailFlash(Craft::t(ProductFeeds::HANDLE, 'feed.saveFailed'));
			Craft::$app->getUrlManager()->setRouteParams([
				'feed' => $feed,
			]);

			return null;
		}

		$this->setSuccessFlash(Craft::t(ProductFeeds::HANDLE, 'feed.saved'));

		return $this->redirectToPostedUrl($feed);
	}

	/**
	 * `Craft.VueAdminTable` posts `id` and expects a JSON success envelope.
	 *
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(ProductFeeds::PERMISSION_EDIT);

		$feedId = $this->toInt($this->request->getRequiredBodyParam('id'));
		$feed = $this->plugin()->getFeeds()->getFeedById($feedId) ?? throw new NotFoundHttpException();
		$this->requireSiteAccess($feed);

		$this->plugin()->getFeeds()->deleteFeedById($feedId);

		return $this->asSuccess(Craft::t(ProductFeeds::HANDLE, 'feed.deleted'));
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionReorder(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(ProductFeeds::PERMISSION_EDIT);

		$ids = Json::decode($this->toString($this->request->getRequiredBodyParam('ids')));
		$feedIds = is_array($ids) ? array_map($this->toInt(...), $ids) : [];

		foreach ($feedIds as $feedId) {
			$this->requireSiteAccess($this->plugin()->getFeeds()->getFeedById($feedId) ?? throw new NotFoundHttpException());
		}

		$this->plugin()->getFeeds()->reorderFeeds($feedIds);

		return $this->asSuccess();
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws Exception
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionDuplicate(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(ProductFeeds::PERMISSION_EDIT);

		$feed = $this->feedFromRequest();
		$duplicate = $this->plugin()->getFeeds()->duplicateFeed($feed);

		if (! $duplicate instanceof Feed) {
			$this->setFailFlash(Craft::t(ProductFeeds::HANDLE, 'feed.saveFailed'));

			return $this->redirect('product-feeds/' . $feed->id);
		}

		return $this->redirect('product-feeds/' . $duplicate->id);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws Exception
	 * @throws ForbiddenHttpException
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionRotateToken(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission(ProductFeeds::PERMISSION_EDIT);

		$feed = $this->feedFromRequest();

		if ($this->plugin()->getFeeds()->rotateToken($feed)) {
			$this->setSuccessFlash(Craft::t(ProductFeeds::HANDLE, 'feed.tokenRotated'));
		} else {
			$this->setFailFlash(Craft::t(ProductFeeds::HANDLE, 'feed.tokenRotateFailed'));
		}

		return $this->redirect('product-feeds/' . $feed->id);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionBuild(): ?Response
	{
		$this->requirePostRequest();
		$this->requirePermission(ProductFeeds::PERMISSION_BUILD);

		$feed = $this->feedFromRequest();
		$this->plugin()->getFeeds()->requestBuild((int) $feed->id);

		// The index button posts by ajax; the edit screen's button posts the page form.
		return $this->asSuccess(Craft::t(ProductFeeds::HANDLE, 'feed.buildQueued'), redirect: 'product-feeds/' . $feed->id);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws Exception
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws LoaderError
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 * @throws RuntimeError
	 * @throws SyntaxError
	 */
	public function actionSourceOptions(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$feed = new Feed();
		$feed->source = $this->toString($this->request->getBodyParam('source', Source::Variants->value));
		$feed->siteId = $this->toInt($this->request->getBodyParam('siteId'));
		$feed->sourceIds = $this->toStringList($this->request->getBodyParam('sourceIds'));

		$this->requireSiteAccess($feed);

		$source = FeedSource::forFeed($feed);

		return $this->asJson([
			'html' => Craft::$app->getView()->renderTemplate('product-feeds/_includes/source-ids', [
				'feed' => $feed,
				'selectableSourceGroups' => $source->selectableSourceGroups(),
			], View::TEMPLATE_MODE_CP),
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws Exception
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws LoaderError
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 * @throws RuntimeError
	 * @throws SyntaxError
	 * @throws Throwable
	 */
	public function actionPreview(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(ProductFeeds::PERMISSION_BUILD);

		$feed = $this->feedFromRequest();

		$spec = FeedSpec::forPlatform($feed->getPlatform());

		return $this->asJson([
			'html' => Craft::$app->getView()->renderTemplate('product-feeds/_includes/preview', [
				'rows' => $this->plugin()->getBuilds()->preview($feed),
				'attributeNames' => array_map($spec->documentName(...), array_keys($spec->attributes())),
				'imageAttributes' => array_map(
					$spec->documentName(...),
					array_values(array_filter([$spec->imageAttribute(), $spec->galleryAttribute()]))
				),
				'idAttribute' => $spec->documentName(StandardAttribute::Id->value),
				'platformLabel' => $feed->getPlatform()->label(),
			], View::TEMPLATE_MODE_CP),
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 * @throws FsException
	 * @throws HttpException
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 */
	public function actionExcludedCsv(): Response
	{
		$feed = $this->plugin()->getFeeds()->getFeedById($this->toInt($this->request->getRequiredParam('feedId')))
			?? throw new NotFoundHttpException();
		$this->requireSiteAccess($feed);

		try {
			$fs = $this->plugin()->getFeeds()->getFs();
		} catch (FeedBuildException) {
			throw new NotFoundHttpException();
		}

		$path = $feed->getExcludedReportPath();

		if (! $fs->fileExists($path)) {
			throw new NotFoundHttpException();
		}

		$stream = $fs->getFileStream($path);
		$content = stream_get_contents($stream);
		if (is_resource($stream)) {
			fclose($stream);
		}

		return $this->response->sendContentAsFile(
			$content === false ? '' : $content,
			sprintf('%s-excluded.csv', $feed->handle),
			[
				'mimeType' => 'text/csv',
			]
		);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws MethodNotAllowedHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionTestImage(): Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();
		$this->requirePermission(ProductFeeds::PERMISSION_BUILD);

		$feed = new Feed();
		$feed->siteId = $this->toInt($this->request->getBodyParam('siteId'));
		$feed->platform = $this->toString($this->request->getBodyParam('platform', Platform::Google->value));
		$feed->source = $this->toString($this->request->getBodyParam('source', Source::Variants->value));
		$feed->sourceIds = $this->toStringList($this->request->getBodyParam('sourceIds'));
		$feed->fieldMapping = Mapping::rows($this->request->getBodyParam('fieldMapping'));
		$this->applyImageSettings($feed);
		$this->requireSiteAccess($feed);

		// The feed is never saved, so nothing else validates what this resolves an image URL from and then
		// fetches server-side.
		if (! $feed->validate(['fieldMapping', 'imageEngine', 'imageFit'])) {
			throw new BadRequestHttpException(implode(' ', $feed->getFirstErrors()));
		}

		return $this->asJson($this->plugin()->getBuilds()->testImage($feed));
	}

	/**
	 * The template reads everything the platform decides straight off `spec`, so only what it cannot
	 * reach from there is passed.
	 *
	 * @return array<string, mixed>
	 * @throws Exception
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 */
	private function editVariables(Feed $feed): array
	{
		$plugin = $this->plugin();
		$spec = FeedSpec::forPlatform($feed->getPlatform());
		$source = $feed->siteId === null ? null : FeedSource::forFeed($feed);

		if ($feed->id === null && $source instanceof FeedSource && $feed->fieldMapping === []) {
			$feed->fieldMapping = $source->defaultMapping();
		}

		return [
			'feed' => $feed,
			'site' => $this->siteOf($feed),
			'spec' => $spec,
			'computedAttributes' => $source?->computedAttributes() ?? [],
			'mappingOptions' => $source instanceof FeedSource ? MappingOptions::forSource($source, $spec) : [],
			'unmappedRequired' => $source instanceof FeedSource ? $plugin->getBuilds()->unmappedRequiredAttributes($feed, $spec, $source) : [],
			'sourcesWithoutUrls' => $source?->sourcesWithoutUrls() ?? [],
			'selectableSourceGroups' => $source?->selectableSourceGroups() ?? [],
			'feedUrl' => $feed->id === null ? null : $plugin->getFeeds()->getFeedUrl($feed),
			'excludedProducts' => $this->excludedProducts($feed),
			'defaultImage' => $this->defaultImage($feed, $spec),
			'filterCondition' => $this->filterBuilder($feed, $source),
			'platformOptions' => $this->enumOptions(Platform::cases()),
			'sourceOptions' => $this->enumOptions(Source::cases()),
			'imageFitOptions' => $this->enumOptions(ImageFit::cases()),
			'imageEngineOptions' => ImageUrl::engineOptions(),
			'craftTransformOptions' => ImageUrl::craftTransformOptions(),
			'noInclude' => Mapping::NO_INCLUDE,
			'imageOverflow' => Mapping::IMAGE_OVERFLOW,
			'canEdit' => Craft::$app->getUser()->checkPermission(ProductFeeds::PERMISSION_EDIT),
			'canBuild' => Craft::$app->getUser()->checkPermission(ProductFeeds::PERMISSION_BUILD),
		];
	}

	/**
	 * @param list<ImageFit|Platform|Source> $cases
	 * @return list<array{label: string, value: string}>
	 */
	private function enumOptions(array $cases): array
	{
		return array_map(
			static fn (ImageFit|Platform|Source $case): array => [
				'label' => $case->label(),
				'value' => $case->value,
			],
			$cases
		);
	}

	private function applyImageSettings(Feed $feed): void
	{
		$request = $this->request;
		$transform = $this->toString($request->getBodyParam('imageTransform'));

		$feed->imageEngine = $this->toString($request->getBodyParam('imageEngine', ImageEngine::None->value));
		$feed->imageTransform = $transform === '' ? null : $transform;
		$feed->imageWidth = $this->toPositiveInt($request->getBodyParam('imageWidth'));
		$feed->imageHeight = $this->toPositiveInt($request->getBodyParam('imageHeight'));
		$feed->imageFit = $this->toString($request->getBodyParam('imageFit', ImageFit::Crop->value));
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 */
	private function feedFromRequest(): Feed
	{
		$feedId = $this->toInt($this->request->getRequiredBodyParam('feedId'));
		$feed = $this->plugin()->getFeeds()->getFeedById($feedId) ?? throw new NotFoundHttpException();
		$this->requireSiteAccess($feed);

		return $feed;
	}

	private function defaultImage(Feed $feed, FeedSpec $spec): ?Asset
	{
		$imageAttribute = $spec->imageAttribute();
		$assetId = $imageAttribute === null
			? ''
			: ($feed->fieldMapping[$imageAttribute]['default'] ?? '');

		return is_numeric($assetId)
			? Craft::$app->getAssets()->getAssetById((int) $assetId)
			: null;
	}

	/**
	 * A capped sample of what the last build skipped, not the whole set. `actionExcludedCsv()` serves the
	 * full list.
	 *
	 * @return list<array{element: ElementInterface, reason: string}>
	 */
	private function excludedProducts(Feed $feed): array
	{
		if ($feed->siteId === null) {
			return [];
		}

		$elementType = FeedSource::forFeed($feed)->elementType();
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

	private function filterBuilder(Feed $feed, ?FeedSource $source): ?ElementCondition
	{
		if (! $source instanceof FeedSource) {
			return null;
		}

		$condition = $this->elementCondition($source->conditionElementType(), $feed->filterCondition);
		$condition->mainTag = 'div';
		$condition->name = 'filterCondition';
		$condition->addRuleLabel = Craft::t(ProductFeeds::HANDLE, 'filter.addRule');

		return $condition;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function postedFilterCondition(Feed $feed, mixed $posted): array
	{
		$rules = is_array($posted) ? ($posted['conditionRules'] ?? []) : [];

		return $this->elementCondition(
			FeedSource::forFeed($feed)->elementType(),
			[
				'conditionRules' => is_array($rules) ? $rules : [],
			],
		)->getConfig();
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @param array<string, mixed> $config
	 */
	private function elementCondition(string $elementType, array $config): ElementCondition
	{
		// Stored config carries `class` from getConfig(), and the builder posts a `config` input.
		// Neither is a settable property on ElementCondition, so passing them through would throw.
		unset($config['class'], $config['config']);

		return new ElementCondition($elementType, Component::cleanseConfig($config));
	}

	/**
	 * @throws ForbiddenHttpException
	 */
	private function requestedSite(): Site
	{
		return Cp::requestedSite() ?? throw new ForbiddenHttpException();
	}

	/**
	 * @throws NotFoundHttpException
	 */
	private function siteOf(Feed $feed): Site
	{
		return Craft::$app->getSites()->getSiteById((int) $feed->siteId) ?? throw new NotFoundHttpException();
	}

	/**
	 * Single-site installs never grant `editSite`, so checking it there would lock out every
	 * non-admin. Craft's own `GlobalsController` makes the same exception.
	 *
	 * @throws ForbiddenHttpException
	 * @throws NotFoundHttpException
	 */
	private function requireSiteAccess(Feed $feed): void
	{
		$site = $this->siteOf($feed);

		if (Craft::$app->getIsMultiSite()) {
			$this->requirePermission('editSite:' . $site->uid);
		}
	}

	private function toString(mixed $value): string
	{
		return is_scalar($value) ? (string) $value : '';
	}

	private function toInt(mixed $value): int
	{
		return is_numeric($value) ? (int) $value : 0;
	}

	private function toPositiveInt(mixed $value): ?int
	{
		return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * @return string[]
	 */
	private function toStringList(mixed $value): array
	{
		if (! is_array($value)) {
			return [];
		}

		return array_values(array_filter(array_map($this->toString(...), $value)));
	}

	private function plugin(): ProductFeeds
	{
		/** @var ProductFeeds $plugin */
		$plugin = ProductFeeds::getInstance();

		return $plugin;
	}
}
