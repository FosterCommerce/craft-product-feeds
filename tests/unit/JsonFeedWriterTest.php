<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\feeds\FeedSpec;
use fostercommerce\productfeeds\feeds\KlaviyoFeed;

class JsonFeedWriterTest extends WriterTestCase
{
	public function testWritesAFlatArrayThatRoundTrips(): void
	{
		$this->writeFeed();

		$items = $this->decode();

		$this->assertCount(2, $items);
		$this->assertSame('SKU-1', $items[0]['$id']);
		$this->assertSame('A shade', $items[0]['$title']);
		$this->assertSame('SKU-2', $items[1]['$id']);
	}

	/**
	 * Klaviyo reads the price and the stock as numbers. Every value reaches the writer as a string, so
	 * the ones it names numeric are cast on the way out.
	 */
	public function testTheNumericFieldsAreNumbersRatherThanStrings(): void
	{
		$this->writeFeed();

		$item = $this->decode()[0];

		$this->assertSame(19.99, $item['$price']);
		// A whole number encodes without its fractional part, so it decodes as an int.
		$this->assertSame(4, $item['$inventory_quantity']);
		$this->assertSame(1, $item['$inventory_policy']);
		$this->assertIsString($item['$id']);
	}

	public function testCategoriesStayAList(): void
	{
		$this->writeFeed();

		$this->assertSame(['Blinds', 'Roman shades'], $this->decode()[0]['categories']);
	}

	/**
	 * A truncated multi-byte character in the database would otherwise fail encoding and take the whole
	 * build down.
	 */
	public function testMalformedUtf8IsSubstitutedRatherThanFailingTheBuild(): void
	{
		$feedWriter = $this->writer();
		$feedWriter->open();
		$feedWriter->writeItem([
			'$id' => 'SKU-1',
			'$description' => "ok\xC3\x28",
		]);
		$feedWriter->close();

		$description = $this->decode()[0]['$description'];

		$this->assertIsString($description);
		$this->assertStringStartsWith('ok', $description);
	}

	protected function spec(): FeedSpec
	{
		return new KlaviyoFeed();
	}

	protected function extension(): string
	{
		return 'json';
	}

	protected function anItem(): array
	{
		return [
			'$id' => 'SKU-1',
		];
	}

	protected function writeFeed(): void
	{
		$feedWriter = $this->writer();
		$feedWriter->open();
		$feedWriter->writeItem([
			'$id' => 'SKU-1',
			'$title' => 'A shade',
			'$link' => 'https://example.test/shades/1',
			'$image_link' => 'https://example.test/1.jpg',
			'$price' => '19.99',
			'categories' => ['Blinds', 'Roman shades'],
			'$inventory_quantity' => '4',
			'$inventory_policy' => '1',
		]);
		$feedWriter->writeItem([
			'$id' => 'SKU-2',
			'$title' => 'Another shade & co',
		]);
		$feedWriter->close();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function decode(): array
	{
		$json = (string) file_get_contents('compress.zlib://' . $this->path);
		$items = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		$this->assertIsList($items);

		/** @var list<array<string, mixed>> $items */
		return $items;
	}
}
