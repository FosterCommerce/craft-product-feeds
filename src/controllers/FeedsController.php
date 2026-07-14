<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\controllers;

use Craft;
use craft\errors\FsException;
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
use fostercommerce\productfeeds\helpers\FeedEditVariables;
use fostercommerce\productfeeds\helpers\FeedIndexTable;
use fostercommerce\productfeeds\helpers\FilterCondition;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\ProductFeeds;
use fostercommerce\productfeeds\sources\FeedSource;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\RangeNotSatisfiableHttpException;
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
		$plugin = ProductFeeds::plugin();
		$view = $this->getView();
		$view->registerAssetBundle(FeedIndexAsset::class);

		$site = $this->requestedSite();
		$siteFeeds = $plugin->getFeeds()->getFeedsBySiteId((int) $site->id);

		$canEdit = Craft::$app->getUser()->checkPermission(ProductFeeds::PERMISSION_EDIT);
		$canBuild = Craft::$app->getUser()->checkPermission(ProductFeeds::PERMISSION_BUILD);
		$tableData = FeedIndexTable::rows($siteFeeds, $canEdit);

		// `feed-index.js` builds the table from these.
		$view->registerJsVar('productFeedsIndex', [
			'tableData' => $tableData,
			'canEdit' => $canEdit,
			'canBuild' => $canBuild,
		]);

		return $this->renderTemplate('product-feeds/index', [
			'tableData' => $tableData,
			'site' => $site,
			'canEdit' => $canEdit,
			'fsConfigured' => $plugin->getFeeds()->hasFs(),
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
		$plugin = ProductFeeds::plugin();
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

		// A new feed opens with its source's suggested mapping already filled in.
		if ($feed->id === null && $feed->fieldMapping === []) {
			$feed->fieldMapping = FeedSource::forFeed($feed)->defaultMapping();
		}

		return $this->renderTemplate('product-feeds/_edit', FeedEditVariables::forFeed($feed, $this->siteOf($feed)));
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
		$plugin = ProductFeeds::plugin();

		$feed = $feedId === null
			? new Feed([
				'siteId' => $this->requestedSite()->id,
			])
			: $plugin->getFeeds()->getFeedById($this->toInt($feedId)) ?? throw new NotFoundHttpException();

		$this->requireSiteAccess($feed);

		$this->applyPostedFeed($feed);
		$feed->name = $this->toString($request->getBodyParam('name', ''));
		$feed->handle = $this->toString($request->getBodyParam('handle', ''));
		$feed->enabled = (bool) $request->getBodyParam('enabled', true);

		// The source is read from the post, so it can only be resolved once the fields above are applied.
		$source = FeedSource::forFeed($feed);
		$feed->filterCondition = FilterCondition::posted($source, $request->getBodyParam('filterCondition'));

		$withoutUrls = $source->sourcesWithoutUrls();
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
		$feed = ProductFeeds::plugin()->getFeeds()->getFeedById($feedId) ?? throw new NotFoundHttpException();
		$this->requireSiteAccess($feed);

		ProductFeeds::plugin()->getFeeds()->deleteFeedById($feedId);

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

		// `Json::decode()` throws on malformed JSON, and a well-formed scalar would otherwise reorder nothing
		// and report success.
		try {
			$ids = Json::decode($this->toString($this->request->getRequiredBodyParam('ids')));
		} catch (InvalidArgumentException) {
			$ids = null;
		}

		if (! is_array($ids)) {
			throw new BadRequestHttpException(Craft::t(ProductFeeds::HANDLE, 'error.invalidValue', [
				'param' => 'ids',
			]));
		}

		$feedIds = array_map($this->toInt(...), $ids);

		foreach ($feedIds as $feedId) {
			$this->requireSiteAccess(ProductFeeds::plugin()->getFeeds()->getFeedById($feedId) ?? throw new NotFoundHttpException());
		}

		ProductFeeds::plugin()->getFeeds()->reorderFeeds($feedIds);

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
		$duplicate = ProductFeeds::plugin()->getFeeds()->duplicateFeed($feed);

		if (! $duplicate instanceof Feed) {
			$this->setFailFlash(Craft::t(ProductFeeds::HANDLE, 'feed.saveFailed'));

			return $this->redirect($feed->getCpEditUrl());
		}

		return $this->redirect($duplicate->getCpEditUrl());
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

		if (ProductFeeds::plugin()->getFeeds()->rotateToken($feed)) {
			$this->setSuccessFlash(Craft::t(ProductFeeds::HANDLE, 'feed.tokenRotated'));
		} else {
			$this->setFailFlash(Craft::t(ProductFeeds::HANDLE, 'feed.tokenRotateFailed'));
		}

		return $this->redirect($feed->getCpEditUrl());
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
		ProductFeeds::plugin()->getBuildQueue()->requestBuild((int) $feed->id);

		// The index button posts by ajax; the edit screen's button posts the page form.
		return $this->asSuccess(Craft::t(ProductFeeds::HANDLE, 'feed.buildQueued'), redirect: $feed->getCpEditUrl());
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
		$this->requirePermission(ProductFeeds::PERMISSION_EDIT);

		$feed = $this->postedFeed();
		$this->requireSiteAccess($feed);

		$source = FeedSource::forFeed($feed);

		return $this->asJson([
			'html' => Craft::$app->getView()->renderTemplate('product-feeds/_includes/source-ids', [
				'feed' => $feed,
				'selectableSourceGroups' => $source->selectableSourceGroups(),
				'sourcesWithoutUrls' => $source->sourcesWithoutUrls(),
				// Only someone who may edit can reach this action.
				'readOnly' => false,
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

		return $this->asJson([
			'html' => Craft::$app->getView()->renderTemplate(
				'product-feeds/_includes/preview',
				FeedEditVariables::forPreview($feed),
				View::TEMPLATE_MODE_CP
			),
		]);
	}

	/**
	 * @throws BadRequestHttpException
	 * @throws ForbiddenHttpException
	 * @throws FsException
	 * @throws InvalidConfigException
	 * @throws NotFoundHttpException
	 * @throws RangeNotSatisfiableHttpException
	 */
	public function actionExcludedCsv(): Response
	{
		$feed = ProductFeeds::plugin()->getFeeds()->getFeedById($this->toInt($this->request->getRequiredParam('feedId')))
			?? throw new NotFoundHttpException();
		$this->requireSiteAccess($feed);

		$filesystem = ProductFeeds::plugin()->getFeeds()->findFs() ?? throw new NotFoundHttpException();

		$path = $feed->getExcludedReportPath();

		if (! $filesystem->fileExists($path)) {
			throw new NotFoundHttpException();
		}

		return $this->response->sendStreamAsFile(
			$filesystem->getFileStream($path),
			sprintf('%s-excluded.csv', $feed->handle),
			[
				'mimeType' => 'text/csv',
				'fileSize' => $filesystem->getFileSize($path),
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

		$feed = $this->postedFeed();
		$this->requireSiteAccess($feed);

		// The edit form does not post the filter, and the test resolves its image from the first element the
		// source returns. Without the saved feed's filter that is the first element in the catalog, which the
		// feed may not publish at all.
		$feedId = $this->toInt($this->request->getBodyParam('feedId'));
		if ($feedId !== 0) {
			$saved = ProductFeeds::plugin()->getFeeds()->getFeedById($feedId) ?? throw new NotFoundHttpException();
			$this->requireSiteAccess($saved);
			$feed->filterCondition = $saved->filterCondition;
		}

		// The feed is never saved, so nothing else validates what this resolves an image URL from and then
		// fetches server-side.
		if (! $feed->validate(['fieldMapping', 'imageEngine', 'imageFit'])) {
			throw new BadRequestHttpException(implode(' ', $feed->getFirstErrors()));
		}

		return $this->asJson(ProductFeeds::plugin()->getBuilds()->testImage($feed)->toArray());
	}

	/**
	 * An unsaved feed carrying what the edit screen currently has on it.
	 */
	private function postedFeed(): Feed
	{
		$feed = new Feed();
		$feed->siteId = $this->toInt($this->request->getBodyParam('siteId'));
		$this->applyPostedFeed($feed);

		return $feed;
	}

	/**
	 * The fields every posted feed carries. A feed's name, handle and filter are only posted by the save
	 * form, so `actionSave()` reads those itself.
	 */
	private function applyPostedFeed(Feed $feed): void
	{
		$request = $this->request;
		$transform = $this->toString($request->getBodyParam('imageTransform'));

		$feed->platform = $this->postedEnum('platform', Platform::values(), Platform::Google->value);
		$feed->source = $this->postedEnum('source', Source::values(), Source::Variants->value);
		$feed->sourceIds = $this->toStringList($request->getBodyParam('sourceIds'));
		$feed->fieldMapping = Mapping::normalizeRows($request->getBodyParam('fieldMapping'));
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
		$feed = ProductFeeds::plugin()->getFeeds()->getFeedById($feedId) ?? throw new NotFoundHttpException();
		$this->requireSiteAccess($feed);

		return $feed;
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

	/**
	 * The platform and the source are resolved through enums that throw, and both are read before the feed
	 * is validated, so a value off the vocabulary has to be rejected as it is read.
	 *
	 * @param list<string> $range
	 * @throws BadRequestHttpException
	 */
	private function postedEnum(string $param, array $range, string $default): string
	{
		$value = $this->toString($this->request->getBodyParam($param, $default));

		if (! in_array($value, $range, true)) {
			throw new BadRequestHttpException(Craft::t(ProductFeeds::HANDLE, 'error.invalidValue', [
				'param' => $param,
			]));
		}

		return $value;
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
}
