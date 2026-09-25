<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\sources;

use craft\elements\Asset;

/**
 * One item a source's `items()` returns.
 */
final readonly class SuppliedItem
{
	/**
	 * @param array<string, string|list<string>|Asset|list<Asset>> $values replace the feed's mapping for their attributes
	 * @param array<string, mixed> $variables available to the feed's Twig values beside `object` and `item`
	 */
	public function __construct(
		public array $values = [],
		public array $variables = [],
	) {
	}
}
