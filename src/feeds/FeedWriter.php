<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use Craft;
use craft\helpers\FileHelper;
use fostercommerce\productfeeds\ProductFeeds;
use yii\base\Exception;

/**
 * Streams a feed document straight into a gzip file.
 */
abstract class FeedWriter
{
	/**
	 * PHP compresses as each write lands, so memory stays flat regardless of item count.
	 */
	private const STREAM_WRAPPER = 'compress.zlib://';

	public function __construct(
		protected readonly string $filePath,
	) {
	}

	/**
	 * @throws Exception
	 */
	abstract public function open(): void;

	/**
	 * @param array<string, string|list<string>> $item attribute handle => value
	 * @throws Exception
	 */
	abstract public function writeItem(array $item): void;

	/**
	 * Called once per batch, so a full disk fails the build here rather than after it is recorded.
	 *
	 * @throws Exception
	 */
	abstract public function flush(): void;

	/**
	 * @throws Exception
	 */
	abstract public function close(): void;

	public function abort(): void
	{
		$this->releaseHandle();

		FileHelper::unlink($this->filePath);
	}

	/**
	 * Drops the writer's handle on the file without finishing the document.
	 */
	abstract protected function releaseHandle(): void;

	protected function streamPath(): string
	{
		return self::STREAM_WRAPPER . $this->filePath;
	}

	/**
	 * A failed build writes its message to the feed's row, which the index table shows the admin.
	 */
	protected function openFailed(): Exception
	{
		return new Exception(Craft::t(ProductFeeds::HANDLE, 'error.writerOpenFailed', [
			'path' => $this->filePath,
		]));
	}

	protected function writeFailed(): Exception
	{
		return new Exception(Craft::t(ProductFeeds::HANDLE, 'error.writerWriteFailed', [
			'path' => $this->filePath,
		]));
	}

	protected function notOpen(): Exception
	{
		return new Exception(Craft::t(ProductFeeds::HANDLE, 'error.writerNotOpen'));
	}
}
