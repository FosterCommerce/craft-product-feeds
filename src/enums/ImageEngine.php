<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

use Craft;
use fostercommerce\productfeeds\ProductFeeds;

enum ImageEngine: string
{
	use EnumValuesTrait;

	case None = 'none';

	case Craft = 'craft';

	case ImagerX = 'imagerx';

	case SmallPics = 'smallpics';

	public function label(): string
	{
		return match ($this) {
			self::None => Craft::t(ProductFeeds::HANDLE, 'imageEngine.none'),
			self::Craft => Craft::t(ProductFeeds::HANDLE, 'imageEngine.craft'),
			self::ImagerX => Craft::t(ProductFeeds::HANDLE, 'imageEngine.imagerx'),
			self::SmallPics => Craft::t(ProductFeeds::HANDLE, 'imageEngine.smallpics'),
		};
	}

	public function isAvailable(): bool
	{
		$handle = $this->pluginHandle();

		return $handle === null || Craft::$app->getPlugins()->getPlugin($handle) !== null;
	}

	public function pluginHandle(): ?string
	{
		return match ($this) {
			self::ImagerX => 'imager-x',
			self::SmallPics => 'smallpics',
			default => null,
		};
	}

	public function transformComponent(): ?string
	{
		return match ($this) {
			self::ImagerX => 'imager',
			self::SmallPics => 'transformer',
			default => null,
		};
	}
}
