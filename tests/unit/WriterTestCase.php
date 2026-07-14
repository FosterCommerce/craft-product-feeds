<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\feeds\FeedWriter;
use fostercommerce\productfeeds\helpers\Gzip;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every writer shares: it streams into a single-member gzip, and it takes its file with it
 * when a build aborts.
 */
abstract class WriterTestCase extends TestCase
{
	protected string $path;

	protected function setUp(): void
	{
		$this->path = sprintf('%s.%s.gz', tempnam(sys_get_temp_dir(), 'feed'), $this->extension());
	}

	protected function tearDown(): void
	{
		if (is_file($this->path)) {
			unlink($this->path);
		}
	}

	public function testUncompressedSizeMatchesTheInflatedLength(): void
	{
		$this->writeFeed();

		$expected = strlen((string) file_get_contents('compress.zlib://' . $this->path));

		$this->assertSame($expected, Gzip::uncompressedSize($this->path));
	}

	public function testAbortRemovesThePartialFile(): void
	{
		$feedWriter = $this->writer();
		$feedWriter->open();
		$feedWriter->writeItem($this->anItem());
		$feedWriter->abort();

		$this->assertFileDoesNotExist($this->path);
	}

	abstract protected function spec(): FeedSpec;

	abstract protected function extension(): string;

	/**
	 * One item, keyed the way this format names its attributes.
	 *
	 * @return array<string, string|list<string>>
	 */
	abstract protected function anItem(): array;

	/**
	 * A whole feed of a couple of items, for the tests that read the written document back.
	 */
	abstract protected function writeFeed(): void;

	protected function writer(): FeedWriter
	{
		return $this->spec()->writer($this->path, 'Test feed', 'https://example.test');
	}
}
