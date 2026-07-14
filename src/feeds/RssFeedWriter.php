<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\feeds;

use XMLWriter;
use yii\base\Exception;

/**
 * Streams an RSS 2.0 document with Google's `g:` namespace.
 */
class RssFeedWriter extends FeedWriter
{
	/**
	 * Everything XML 1.0 forbids in character data. `XMLWriter::text()` passes these through unescaped,
	 * and one of them makes the whole document unparseable.
	 *
	 * @see https://www.w3.org/TR/xml/#charsets
	 */
	private const INVALID_XML_CHARACTERS = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

	private ?XMLWriter $xmlWriter = null;

	public function __construct(
		string $filePath,
		private readonly string $channelTitle,
		private readonly string $channelLink,
		private readonly string $namespacePrefix,
		private readonly string $namespaceUri,
	) {
		parent::__construct($filePath);
	}

	/**
	 * @throws Exception
	 */
	public function open(): void
	{
		$writer = new XMLWriter();

		if (! $writer->openUri($this->streamPath())) {
			throw $this->openFailed();
		}

		$writer->startDocument('1.0', 'UTF-8');
		$writer->startElement('rss');
		$writer->writeAttribute('version', '2.0');
		$writer->writeAttributeNs('xmlns', $this->namespacePrefix, null, $this->namespaceUri);
		$writer->startElement('channel');
		$writer->writeElement('title', $this->clean($this->channelTitle));
		$writer->writeElement('link', $this->clean($this->channelLink));
		$writer->writeElement('description', $this->clean($this->channelTitle));

		$this->xmlWriter = $writer;
	}

	/**
	 * @throws Exception
	 */
	public function writeItem(array $item): void
	{
		$writer = $this->xmlWriter ?? throw $this->notOpen();

		$writer->startElement('item');

		foreach ($item as $attributeName => $value) {
			foreach (is_array($value) ? $value : [$value] as $singleValue) {
				$writer->startElementNs($this->namespacePrefix, $attributeName, null);
				$writer->text($this->clean($singleValue));
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
		$writer = $this->xmlWriter ?? throw $this->notOpen();

		$this->assertFlushed($writer->flush());
	}

	/**
	 * @throws Exception
	 */
	public function close(): void
	{
		$writer = $this->xmlWriter ?? throw $this->notOpen();

		$writer->endElement();
		$writer->endElement();
		$writer->endDocument();
		$this->assertFlushed($writer->flush());

		// XMLWriter has no close(): the gzip stream writes its trailer only when the last reference drops.
		$this->xmlWriter = null;
		unset($writer);
	}

	protected function releaseHandle(): void
	{
		$this->xmlWriter = null;
	}

	private function clean(string $value): string
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
	 * `XMLWriter::flush()` returns a byte count for URI writers. libxml reports an IO error as false or a
	 * negative count; zero just means nothing was buffered.
	 *
	 * @throws Exception
	 */
	private function assertFlushed(int|string|false $result): void
	{
		if ($result === false || (is_int($result) && $result < 0)) {
			throw $this->writeFailed();
		}
	}
}
