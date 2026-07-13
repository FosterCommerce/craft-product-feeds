<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

use Craft;
use fostercommerce\productfeeds\ProductFeeds;

/**
 * Craft, Imager X and Small Pics all spell these two modes the same way, so the value is passed to
 * whichever engine the feed uses.
 */
enum ImageFit: string
{
	case Crop = 'crop';

	case Fit = 'fit';

	public function label(): string
	{
		return match ($this) {
			self::Crop => Craft::t(ProductFeeds::HANDLE, 'imageFit.crop'),
			self::Fit => Craft::t(ProductFeeds::HANDLE, 'imageFit.fit'),
		};
	}
}
