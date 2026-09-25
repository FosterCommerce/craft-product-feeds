<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\migrations;

use craft\db\Migration;
use fostercommerce\productfeeds\db\Table;

/**
 * A custom source's handle is stored on the feed, and can be longer than a built-in source's.
 */
class m260925_041148_widen_feed_source extends Migration
{
	public function safeUp(): bool
	{
		$this->alterColumn(Table::FEEDS, 'source', (string) $this->string()->notNull());

		return true;
	}
}
