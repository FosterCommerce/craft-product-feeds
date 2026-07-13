<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

use Craft;
use fostercommerce\productfeeds\ProductFeeds;

enum ImageEngine: string
{
	case None = 'none';

	case Craft = 'craft';

	case ImagerX = 'imagerx';

	case SmallPics = 'smallpics';

	public function label(): string
	{
		return Craft::t(ProductFeeds::HANDLE, sprintf('imageEngine.%s', $this->value));
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
