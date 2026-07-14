<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\helpers\Mapping;
use PHPUnit\Framework\TestCase;

class MappingTest extends TestCase
{
	public function testParsesTheSentinels(): void
	{
		$this->assertSame(Mapping::NO_INCLUDE, Mapping::parse('')['kind']);
		$this->assertSame(Mapping::NO_INCLUDE, Mapping::parse(Mapping::NO_INCLUDE)['kind']);
		$this->assertSame(Mapping::USE_DEFAULT, Mapping::parse(Mapping::USE_DEFAULT)['kind']);
	}

	public function testParsesPrefixedSources(): void
	{
		$this->assertSame([
			'kind' => Mapping::ELEMENT,
			'value' => 'product.url',
		], Mapping::parse('element:product.url'));

		$this->assertSame([
			'kind' => Mapping::FIELD,
			'value' => 'previewImage',
		], Mapping::parse('field:previewImage'));

		$this->assertSame([
			'kind' => Mapping::PRODUCT_FIELD,
			'value' => 'previewImage',
		], Mapping::parse('productField:previewImage'));
	}

	/**
	 * A field UID can't say whether the admin meant the variant layout or the product layout, so an
	 * unprefixed or unknown source has to fall back to not including the attribute.
	 */
	public function testUnknownPrefixesAreNotIncluded(): void
	{
		$this->assertSame(Mapping::NO_INCLUDE, Mapping::parse('abc-123')['kind']);
		$this->assertSame(Mapping::NO_INCLUDE, Mapping::parse('bogus:abc-123')['kind']);
	}

	public function testParsesImageOverflow(): void
	{
		$this->assertSame(Mapping::IMAGE_OVERFLOW, Mapping::parse(Mapping::IMAGE_OVERFLOW)['kind']);
	}

	public function testBuildRoundTrips(): void
	{
		$source = Mapping::build(Mapping::PRODUCT_FIELD, 'productBrand');

		$this->assertSame([
			'kind' => Mapping::PRODUCT_FIELD,
			'value' => 'productBrand',
		], Mapping::parse($source));
	}

	public function testRowsFillInBothKeys(): void
	{
		$this->assertSame([
			'title' => [
				'source' => 'element:product.title',
				'default' => '',
			],
			'condition' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => 'new',
			],
		], Mapping::normalizeRows([
			'title' => [
				'source' => 'element:product.title',
			],
			'condition' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => 'new',
			],
		]));
	}

	/**
	 * Craft's element picker posts the default as an array of IDs.
	 */
	public function testRowsTakeTheFirstPostedAsset(): void
	{
		$rows = Mapping::normalizeRows([
			'image_link' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => ['482', '907'],
			],
		]);

		$this->assertSame('482', $rows['image_link']['default']);
	}

	public function testRowsDropWhatCannotBeAMappingRow(): void
	{
		$this->assertSame([], Mapping::normalizeRows(null));
		$this->assertSame([], Mapping::normalizeRows('noinclude'));
		$this->assertSame([], Mapping::normalizeRows([
			'title' => 'element:title',
		]));
	}
}
