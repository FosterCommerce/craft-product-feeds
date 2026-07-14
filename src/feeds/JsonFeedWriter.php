<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use craft\helpers\Json;
use yii\base\Exception;

/**
 * Streams a flat JSON array, one item at a time, so the whole catalog is never held in memory.
 */
class JsonFeedWriter extends FeedWriter
{
	/**
	 * `JSON_INVALID_UTF8_SUBSTITUTE` keeps invalid UTF-8 in the database from failing the encode.
	 */
	private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

	/**
	 * @var resource|null
	 */
	private $handle;

	private bool $wroteItem = false;

	/**
	 * @param list<string> $numericAttributes document names the platform reads as numbers rather than
	 * strings
	 */
	public function __construct(
		string $filePath,
		private readonly array $numericAttributes = [],
	) {
		parent::__construct($filePath);
	}

	/**
	 * @throws Exception
	 */
	public function open(): void
	{
		$handle = fopen($this->streamPath(), 'wb');

		if ($handle === false) {
			throw $this->openFailed();
		}

		$this->handle = $handle;

		$this->write('[');
	}

	/**
	 * @throws Exception
	 */
	public function writeItem(array $item): void
	{
		$json = Json::encode($this->castNumericValues($item), self::ENCODE_FLAGS);

		$this->write($this->wroteItem ? ',' . $json : $json);
		$this->wroteItem = true;
	}

	/**
	 * @throws Exception
	 */
	public function flush(): void
	{
		$handle = $this->handle ?? throw $this->notOpen();

		if (! fflush($handle)) {
			throw $this->writeFailed();
		}
	}

	/**
	 * @throws Exception
	 */
	public function close(): void
	{
		$this->write(']');

		$handle = $this->handle ?? throw $this->notOpen();
		$this->handle = null;

		// The deflate filter reports a successful `fwrite()` before the bytes exist on disk: the last of
		// them, and the gzip trailer, are written here. A discarded failure publishes a truncated archive
		// as a successful build.
		if (! fclose($handle)) {
			throw $this->writeFailed();
		}
	}

	protected function releaseHandle(): void
	{
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}

		$this->handle = null;
	}

	/**
	 * Every value reaches the writer as a string, and the platform reads some of them as numbers.
	 *
	 * @param array<string, string|list<string>> $item
	 * @return array<string, string|float|list<string>>
	 */
	private function castNumericValues(array $item): array
	{
		$typed = [];

		foreach ($item as $attribute => $value) {
			$typed[$attribute] = is_string($value) && in_array($attribute, $this->numericAttributes, true) && is_numeric($value)
				? (float) $value
				: $value;
		}

		return $typed;
	}

	/**
	 * @throws Exception
	 */
	private function write(string $data): void
	{
		$handle = $this->handle ?? throw $this->notOpen();

		if (fwrite($handle, $data) === false) {
			throw $this->writeFailed();
		}
	}
}
