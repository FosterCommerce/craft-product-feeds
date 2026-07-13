<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use XMLWriter;
use yii\base\Exception;

/**
 * Streams an RSS 2.0 document straight into gzip.
 *
 * The `compress.zlib://` wrapper compresses as `XMLWriter` writes, so memory stays flat regardless of
 * item count.
 */
class RssFeedWriter implements FeedWriterInterface
{
	/**
	 * Everything XML 1.0 forbids in character data. `XMLWriter::text()` passes these bytes through
	 * unescaped, and one of them makes the whole document unparseable.
	 *
	 * @see https://www.w3.org/TR/xml/#charsets
	 */
	private const INVALID_XML_CHARACTERS = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

	private ?XMLWriter $xmlWriter = null;

	public function __construct(
		private readonly string $filePath,
		private readonly string $channelTitle,
		private readonly string $channelLink,
		private readonly string $namespacePrefix,
		private readonly string $namespaceUri,
	) {
	}

	/**
	 * @throws Exception
	 */
	public function open(): void
	{
		$writer = new XMLWriter();

		if (! $writer->openUri('compress.zlib://' . $this->filePath)) {
			throw new Exception(sprintf('Could not open “%s” for writing.', $this->filePath));
		}

		$writer->startDocument('1.0', 'UTF-8');
		$writer->startElement('rss');
		$writer->writeAttribute('version', '2.0');
		$writer->writeAttributeNs('xmlns', $this->namespacePrefix, null, $this->namespaceUri);
		$writer->startElement('channel');
		$writer->writeElement('title', self::clean($this->channelTitle));
		$writer->writeElement('link', self::clean($this->channelLink));
		$writer->writeElement('description', self::clean($this->channelTitle));

		$this->xmlWriter = $writer;
	}

	/**
	 * @throws Exception
	 */
	public function writeItem(array $item): void
	{
		$writer = $this->xmlWriter ?? throw new Exception('The feed writer is not open.');

		$writer->startElement('item');

		foreach ($item as $handle => $value) {
			foreach (is_array($value) ? $value : [$value] as $single) {
				$writer->startElementNs($this->namespacePrefix, $handle, null);
				$writer->text(self::clean($single));
				$writer->endElement();
			}
		}

		$writer->endElement();
	}

	/**
	 * @throws Exception
	 */
	public function flush(): void
	{
		$writer = $this->xmlWriter ?? throw new Exception('The feed writer is not open.');

		$this->assertFlushed($writer->flush());
	}

	/**
	 * @throws Exception
	 */
	public function close(): void
	{
		$writer = $this->xmlWriter ?? throw new Exception('The feed writer is not open.');

		$writer->endElement();
		$writer->endElement();
		$writer->endDocument();
		$this->assertFlushed($writer->flush());

		// XMLWriter has no close(). The gzip stream writes its trailer when the last reference drops, so
		// the local has to go too.
		$this->xmlWriter = null;
		unset($writer);

		clearstatcache(true, $this->filePath);
		if (! is_file($this->filePath) || filesize($this->filePath) === 0) {
			throw new Exception(sprintf('The feed file “%s” was not written.', $this->filePath));
		}
	}

	public function abort(): void
	{
		$this->xmlWriter = null;

		if (is_file($this->filePath)) {
			@unlink($this->filePath);
		}
	}

	public static function clean(string $value): string
	{
		$cleaned = preg_replace(self::INVALID_XML_CHARACTERS, '', $value);

		if ($cleaned !== null) {
			return $cleaned;
		}

		// preg_replace returns null on malformed UTF-8, which a truncated 4-byte character in the
		// database will produce.
		$scrubbed = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

		return (string) preg_replace(self::INVALID_XML_CHARACTERS, '', $scrubbed);
	}

	/**
	 * `XMLWriter::flush()` returns a byte count for URI writers and the buffer for memory writers.
	 * libxml reports IO errors as false or a negative count. Zero means nothing was buffered.
	 *
	 * @throws Exception
	 */
	private function assertFlushed(int|string|false $result): void
	{
		if ($result === false || (is_int($result) && $result < 0)) {
			throw new Exception(sprintf('Failed writing feed data to “%s”.', $this->filePath));
		}
	}
}
