<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

use craft\base\Model;

class Settings extends Model
{
	/**
	 * Required: on Cloud and Servd the queue worker and the web server run in different containers, so
	 * local disk cannot work.
	 */
	public ?string $fsHandle = null;

	public int $batchSize = 500;

	/**
	 * The job's TTR, and how long a build may sit in `building` before `BuildQueue::isDue()` counts it as
	 * stalled. Craft Cloud caps queue jobs at 15 minutes, so the TTR only applies to self-hosted sites.
	 */
	public int $buildTimeout = 3600;

	/**
	 * How stale a feed may get before `feeds/build` queues it again. A feed built more recently is
	 * skipped, so the command can run more often than this.
	 */
	public int $buildInterval = 3600;

	/**
	 * Stock is not covered: it lives on a separate table, and changing it never saves the variant.
	 */
	public bool $rebuildOnChange = true;

	/**
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['fsHandle'], 'required'],
			[['fsHandle'], 'string'],
			[['batchSize', 'buildTimeout', 'buildInterval'],
				'integer',
				'min' => 1],
			[['rebuildOnChange'], 'boolean'],
		];
	}
}
