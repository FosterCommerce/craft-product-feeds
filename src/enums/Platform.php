<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

use Craft;
use fostercommerce\productfeeds\ProductFeeds;

enum Platform: string
{
	case Google = 'google';

	case Meta = 'meta';

	case Microsoft = 'microsoft';

	case Pinterest = 'pinterest';

	case TikTok = 'tiktok';

	public function label(): string
	{
		return match ($this) {
			self::Google => Craft::t(ProductFeeds::HANDLE, 'platform.google'),
			self::Meta => Craft::t(ProductFeeds::HANDLE, 'platform.meta'),
			self::Microsoft => Craft::t(ProductFeeds::HANDLE, 'platform.microsoft'),
			self::Pinterest => Craft::t(ProductFeeds::HANDLE, 'platform.pinterest'),
			self::TikTok => Craft::t(ProductFeeds::HANDLE, 'platform.tiktok'),
		};
	}
}
