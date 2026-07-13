<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

/**
 * The custom fields a feed reads, so an edit that touches none of them does not rebuild it.
 */
final readonly class WatchedFields
{
	/**
	 * @param list<string> $mapped handles the feed's mapping reads
	 * @param list<string> $filter handles the feed's filter rules name
	 * @param bool $hasRelationRule the filter carries a Related To rule, which names no field of its own,
	 * so any relation edit has to count
	 */
	public function __construct(
		public array $mapped,
		public array $filter,
		public bool $hasRelationRule,
	) {
	}
}
