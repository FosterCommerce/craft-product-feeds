<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\helpers;

/**
 * Encodes and decodes a mapping row's `source`, a single `kind:value` string so it can be a
 * `<select>` option value.
 *
 * Fields are keyed by their layout-effective handle, not UID: a layout clones a field and can
 * override its handle, so one global field may appear twice under two handles, and `getFieldValue()`
 * takes the handle.
 */
final class Mapping
{
	public const NO_INCLUDE = 'noinclude';

	public const USE_DEFAULT = 'usedefault';

	/**
	 * The gallery attribute's default: the `image_link` source's images after the first, up to the
	 * platform's gallery limit.
	 */
	public const IMAGE_OVERFLOW = 'imageoverflow';

	public const ELEMENT = 'element';

	public const FIELD = 'field';

	/**
	 * A custom field on the variant's product. Variants source only.
	 */
	public const PRODUCT_FIELD = 'productField';

	/**
	 * @return array{kind: string, value: string}
	 */
	public static function parse(string $source): array
	{
		if (in_array($source, [self::USE_DEFAULT, self::IMAGE_OVERFLOW], true)) {
			return [
				'kind' => $source,
				'value' => '',
			];
		}

		[$kind, $value] = array_pad(explode(':', $source, 2), 2, '');

		return in_array($kind, [self::ELEMENT, self::FIELD, self::PRODUCT_FIELD], true)
			? [
				'kind' => $kind,
				'value' => $value,
			]
			: [
				'kind' => self::NO_INCLUDE,
				'value' => '',
			];
	}

	public static function build(string $kind, string $value): string
	{
		return sprintf('%s:%s', $kind, $value);
	}

	/**
	 * @return array<string, array{source: string, default: string}>
	 */
	public static function rows(mixed $value): array
	{
		if (! is_array($value)) {
			return [];
		}

		$rows = [];

		foreach ($value as $attribute => $row) {
			if (! is_array($row)) {
				continue;
			}

			$rows[(string) $attribute] = [
				'source' => self::scalar($row['source'] ?? ''),
				'default' => self::scalar($row['default'] ?? ''),
			];
		}

		return $rows;
	}

	/**
	 * Craft's element picker posts an array of IDs. An image default takes a single asset, so only
	 * the first is used.
	 */
	private static function scalar(mixed $value): string
	{
		if (is_array($value)) {
			$value = reset($value);
		}

		return is_scalar($value) ? (string) $value : '';
	}
}
