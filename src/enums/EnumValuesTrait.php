<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

/**
 * The backing values of a string enum, for the validators that range over them.
 *
 * Commerce's own `EnumHelpersTrait` does the same, but types its return as a plain array, which loses
 * the shape the validators are checked against.
 */
trait EnumValuesTrait
{
	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
