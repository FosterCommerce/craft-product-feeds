<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use fostercommerce\productfeeds\models\Feed;
use fostercommerce\productfeeds\models\Settings;
use fostercommerce\productfeeds\services\AutoRebuild;
use fostercommerce\productfeeds\services\BuildQueue;
use fostercommerce\productfeeds\services\Builds;
use fostercommerce\productfeeds\services\Feeds;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\queue\Queue;

/**
 * @method Settings getSettings()
 */
class ProductFeeds extends BasePlugin
{
	public const HANDLE = 'product-feeds';

	public const FILE_PREFIX = 'product-feeds';

	public const PERMISSION_VIEW = 'productFeeds:view';

	public const PERMISSION_EDIT = 'productFeeds:edit';

	public const PERMISSION_BUILD = 'productFeeds:build';

	public string $schemaVersion = '1.0.0';

	public bool $hasCpSection = true;

	public bool $hasCpSettings = true;

	/**
	 * The plugin. Never null inside the plugin's own code.
	 */
	public static function plugin(): self
	{
		/** @var self $plugin */
		$plugin = self::getInstance();

		return $plugin;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'feeds' => Feeds::class,
				'builds' => Builds::class,
				'buildQueue' => BuildQueue::class,
				'autoRebuild' => AutoRebuild::class,
			],
		];
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function init(): void
	{
		parent::init();

		$this->registerCpRoutes();
		$this->registerSiteRoutes();
		$this->registerPermissions();

		if ($this->getSettings()->rebuildOnChange) {
			$this->registerElementEvents();
		}
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function getFeeds(): Feeds
	{
		/** @var Feeds $feeds */
		$feeds = $this->get('feeds');

		return $feeds;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function getBuilds(): Builds
	{
		/** @var Builds $builds */
		$builds = $this->get('builds');

		return $builds;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function getBuildQueue(): BuildQueue
	{
		/** @var BuildQueue $buildQueue */
		$buildQueue = $this->get('buildQueue');

		return $buildQueue;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function getAutoRebuild(): AutoRebuild
	{
		/** @var AutoRebuild $autoRebuild */
		$autoRebuild = $this->get('autoRebuild');

		return $autoRebuild;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getCpNavItem(): ?array
	{
		$navItem = parent::getCpNavItem();
		if ($navItem === null) {
			return null;
		}

		$navItem['label'] = Craft::t(self::HANDLE, 'nav.productFeeds');

		return $navItem;
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	/**
	 * @throws Exception
	 * @throws LoaderError
	 * @throws RuntimeError
	 * @throws SyntaxError
	 */
	protected function settingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('product-feeds/settings', [
			'settings' => $this->getSettings(),
		]);
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function registerElementEvents(): void
	{
		// Resolved once, so the handlers below don't each have to deal with `get()` throwing.
		$autoRebuild = $this->getAutoRebuild();

		// A worker runs every job in one process, so this service outlives the feed list it memoizes.
		Event::on(
			Queue::class,
			Queue::EVENT_BEFORE_EXEC,
			static function () use ($autoRebuild): void {
				$autoRebuild->forgetFeeds();
			}
		);

		Event::on(
			Elements::class,
			Elements::EVENT_AFTER_SAVE_ELEMENT,
			static function (ElementEvent $event) use ($autoRebuild): void {
				$autoRebuild->onSave($event->element, $event->isNew);
			}
		);

		Event::on(
			Elements::class,
			Elements::EVENT_AFTER_DELETE_ELEMENT,
			static function (ElementEvent $event) use ($autoRebuild): void {
				$autoRebuild->onDelete($event->element);
			}
		);

		Event::on(
			Elements::class,
			Elements::EVENT_AFTER_RESTORE_ELEMENT,
			static function (ElementEvent $event) use ($autoRebuild): void {
				$autoRebuild->onRestore($event->element);
			}
		);
	}

	private function registerCpRoutes(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			static function (RegisterUrlRulesEvent $event): void {
				$event->rules['product-feeds'] = 'product-feeds/feeds/index';
				$event->rules['product-feeds/new'] = 'product-feeds/feeds/edit';
				$event->rules['product-feeds/<feedId:\d+>'] = 'product-feeds/feeds/edit';
			}
		);
	}

	private function registerSiteRoutes(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_SITE_URL_RULES,
			static function (RegisterUrlRulesEvent $event): void {
				$feedFile = sprintf(
					'%s/<handle:[a-zA-Z][a-zA-Z0-9_]*>-<token:[A-Za-z0-9_-]{%d}>.<extension:[a-z]+>',
					self::FILE_PREFIX,
					Feed::TOKEN_LENGTH
				);

				$event->rules[$feedFile . '.gz'] = 'product-feeds/feed/serve-compressed';
				$event->rules[$feedFile] = 'product-feeds/feed/serve';
			}
		);
	}

	private function registerPermissions(): void
	{
		Event::on(
			UserPermissions::class,
			UserPermissions::EVENT_REGISTER_PERMISSIONS,
			static function (RegisterUserPermissionsEvent $event): void {
				$event->permissions[] = [
					'heading' => Craft::t(self::HANDLE, 'nav.productFeeds'),
					'permissions' => [
						self::PERMISSION_VIEW => [
							'label' => Craft::t(self::HANDLE, 'permission.viewFeeds'),
							'nested' => [
								self::PERMISSION_EDIT => [
									'label' => Craft::t(self::HANDLE, 'permission.editFeeds'),
								],
								self::PERMISSION_BUILD => [
									'label' => Craft::t(self::HANDLE, 'permission.buildFeeds'),
								],
							],
						],
					],
				];
			}
		);
	}
}
