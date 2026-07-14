<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\helpers;

use Craft;
use craft\elements\Asset;
use fostercommerce\productfeeds\enums\ImageEngine;
use fostercommerce\productfeeds\models\ImageTransform;
use yii\base\InvalidConfigException;
use yii\base\Module;

/**
 * Resolves the URL a feed publishes for an image, through the engine the admin chose.
 *
 * Transforms are generated immediately so the feed emits a real image URL rather than Craft's
 * deferred `generate-transform` action URL.
 */
final class ImageUrl
{
	/**
	 * @throws InvalidConfigException
	 */
	public static function forAsset(Asset $asset, ImageTransform $transform): ?string
	{
		return match ($transform->imageEngine) {
			ImageEngine::None => $asset->getUrl(),
			ImageEngine::Craft => self::craftUrl($asset, $transform),
			ImageEngine::ImagerX, ImageEngine::SmallPics => self::pluginUrl($asset, $transform),
		};
	}

	/**
	 * @throws InvalidConfigException
	 */
	private static function craftUrl(Asset $asset, ImageTransform $transform): ?string
	{
		if (! $transform->hasNamedTransform() && ! $transform->hasSize()) {
			return $asset->getUrl();
		}

		$url = $asset->getUrl(
			$transform->hasNamedTransform() ? $transform->namedTransform : $transform->toConfig(),
			true,
		);

		return is_string($url) ? $url : null;
	}

	/**
	 * Neither plugin is a composer dependency, so the engine is reached by duck typing: both expose
	 * `transformImage()` on a component and return an object with `getUrl()`.
	 *
	 * @throws InvalidConfigException
	 */
	private static function pluginUrl(Asset $asset, ImageTransform $transform): ?string
	{
		$handle = $transform->imageEngine->pluginHandle();
		$component = $transform->imageEngine->transformComponent();
		if ($handle === null || $component === null) {
			return null;
		}

		$plugin = Craft::$app->getPlugins()->getPlugin($handle);
		if (! $plugin instanceof Module) {
			return null;
		}

		$service = $plugin->get($component, false);
		if (! is_object($service) || ! method_exists($service, 'transformImage')) {
			return null;
		}

		$transformed = $service->transformImage($asset, $transform->toConfig());
		if (! is_object($transformed) || ! method_exists($transformed, 'getUrl')) {
			return null;
		}

		$url = $transformed->getUrl();

		return is_string($url) ? $url : null;
	}
}
