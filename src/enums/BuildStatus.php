<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

use Craft;
use fostercommerce\productfeeds\ProductFeeds;

enum BuildStatus: string
{
	use EnumValuesTrait;

	case Pending = 'pending';

	case Building = 'building';

	case Ok = 'ok';

	case Failed = 'failed';

	public function label(): string
	{
		return match ($this) {
			self::Pending => Craft::t(ProductFeeds::HANDLE, 'buildStatus.pending'),
			self::Building => Craft::t(ProductFeeds::HANDLE, 'buildStatus.building'),
			self::Ok => Craft::t(ProductFeeds::HANDLE, 'buildStatus.ok'),
			self::Failed => Craft::t(ProductFeeds::HANDLE, 'buildStatus.failed'),
		};
	}
}
