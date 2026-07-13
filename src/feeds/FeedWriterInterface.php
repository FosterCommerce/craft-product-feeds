<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use yii\base\Exception;

interface FeedWriterInterface
{
	/**
	 * @throws Exception
	 */
	public function open(): void;

	/**
	 * @param array<string, string|list<string>> $item attribute handle => value
	 * @throws Exception
	 */
	public function writeItem(array $item): void;

	/**
	 * Called once per batch, so a full disk fails the build here rather than after it is recorded.
	 *
	 * @throws Exception
	 */
	public function flush(): void;

	/**
	 * @throws Exception
	 */
	public function close(): void;

	public function abort(): void;
}
