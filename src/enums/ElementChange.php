<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\enums;

/**
 * What happened to an element, as far as a feed is concerned. A restored element counts as created:
 * it is new to every feed, whatever it was before it was trashed.
 */
enum ElementChange
{
	case Created;

	case Updated;

	case Deleted;
}
