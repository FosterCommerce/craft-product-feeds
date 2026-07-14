<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

use Craft;
use fostercommerce\productfeeds\ProductFeeds;

enum Source: string
{
	use EnumValuesTrait;

	case Variants = 'variants';

	case Entries = 'entries';

	public function label(): string
	{
		return match ($this) {
			self::Variants => Craft::t(ProductFeeds::HANDLE, 'source.variants'),
			self::Entries => Craft::t(ProductFeeds::HANDLE, 'source.entries'),
		};
	}
}
