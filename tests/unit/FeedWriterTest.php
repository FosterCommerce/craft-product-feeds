<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\feeds\GoogleFeed;

class FeedWriterTest extends WriterTestCase
{
	/**
	 * Every platform's document declares Google's namespace. Spelled out rather than read off the spec,
	 * so a typo in the spec fails here.
	 */
	private const GOOGLE_NAMESPACE_URI = 'http://base.google.com/ns/1.0';

	public function testWritesNamespacedRssThatRoundTrips(): void
	{
		$this->writeFeed();

		$xml = (string) file_get_contents('compress.zlib://' . $this->path);
		$document = simplexml_load_string($xml);

		$this->assertNotFalse($document);
		$this->assertSame('rss', $document->getName());
		$this->assertSame(self::GOOGLE_NAMESPACE_URI, $document->getDocNamespaces()['g'] ?? null);
		$this->assertCount(2, $document->channel->item);
	}

	public function testRepeatedAttributesEmitOneTagPerValue(): void
	{
		$this->writeFeed();

		$xml = (string) file_get_contents('compress.zlib://' . $this->path);
		$document = simplexml_load_string($xml);
		$this->assertNotFalse($document);

		$google = $document->channel->item[0]->children(self::GOOGLE_NAMESPACE_URI);

		$this->assertSame('SKU-1', (string) $google->id);
		$this->assertCount(2, $google->additional_image_link);
	}

	/**
	 * The `.xml` route inflates the stored artifact as it streams. `zlib.inflate` reads only the
	 * first gzip member, so a build that ever concatenated members would silently serve a truncated
	 * feed. This is the regression test for that.
	 */
	public function testInflateFilterRecoversTheWholeDocument(): void
	{
		$this->writeFeed();

		$stream = fopen($this->path, 'rb');
		$this->assertIsResource($stream);
		stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, [
			'window' => 31,
		]);

		$inflated = '';
		while (! feof($stream)) {
			$inflated .= fread($stream, 8192);
		}

		fclose($stream);

		$this->assertSame((string) file_get_contents('compress.zlib://' . $this->path), $inflated);
		$this->assertNotFalse(simplexml_load_string($inflated));
	}

	/**
	 * XMLWriter writes these bytes through unescaped, and libxml then refuses to parse the document.
	 * One vertical tab in one product description would take the whole feed down.
	 */
	public function testIllegalXmlCharactersAreStripped(): void
	{
		$description = $this->writtenDescription("Roman shade\x0Bwith\x0Ccontrol\x08system");

		$this->assertSame('Roman shadewithcontrolsystem', $description);
	}

	public function testTabsAndNewlinesSurvive(): void
	{
		$this->assertSame("a\tb\nc", $this->writtenDescription("a\tb\nc"));
	}

	/**
	 * `preg_replace` returns null on malformed UTF-8. Without the scrub fallback the value would be cast
	 * to an empty string, silently dropping a product's description instead of its bad byte.
	 */
	public function testMalformedUtf8IsScrubbedRatherThanEmptied(): void
	{
		$description = $this->writtenDescription("ok\xC3\x28");

		$this->assertNotSame('', $description);
		$this->assertStringStartsWith('ok', $description);
		$this->assertTrue(mb_check_encoding($description, 'UTF-8'));
	}

	protected function spec(): FeedSpec
	{
		return new GoogleFeed();
	}

	protected function extension(): string
	{
		return 'xml';
	}

	protected function anItem(): array
	{
		return [
			'id' => 'SKU-1',
		];
	}

	protected function writeFeed(): void
	{
		$feedWriter = $this->writer();
		$feedWriter->open();
		$feedWriter->writeItem([
			'id' => 'SKU-1',
			'title' => 'A shade',
			'image_link' => 'https://example.test/1.jpg',
			'additional_image_link' => ['https://example.test/2.jpg', 'https://example.test/3.jpg'],
		]);
		$feedWriter->writeItem([
			'id' => 'SKU-2',
			'title' => 'Another shade & co',
		]);
		$feedWriter->close();
	}

	/**
	 * The description as the document carries it, once the writer has cleaned the value on its way in.
	 */
	private function writtenDescription(string $description): string
	{
		$feedWriter = $this->writer();
		$feedWriter->open();
		$feedWriter->writeItem([
			'id' => 'SKU-1',
			'description' => $description,
		]);
		$feedWriter->close();

		$document = simplexml_load_string((string) file_get_contents('compress.zlib://' . $this->path));
		$this->assertNotFalse($document);

		return (string) $document->channel->item[0]->children(self::GOOGLE_NAMESPACE_URI)->description;
	}
}
