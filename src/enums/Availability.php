<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

/**
 * Canonical values. A platform that words them differently rewrites them in `FeedSpec::finalizeItem()`.
 */
enum Availability: string
{
	case InStock = 'in_stock';

	case OutOfStock = 'out_of_stock';

	/** Never derived: Commerce has no preorder concept, so it can only be set as a mapping default. */
	case Preorder = 'preorder';

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
