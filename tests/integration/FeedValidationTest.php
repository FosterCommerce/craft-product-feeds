<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\Feed;

/**
 * `Feed::validate()` is the only server-side guard on a posted feed, and it resolves the platform and
 * source through enums that throw on an unknown value. These assert it rejects rather than fatals, and
 * that the mapping validators do not run ahead of that rejection.
 */
class FeedValidationTest extends IntegrationTestCase
{
	public function testAValidFeedValidates(): void
	{
		$this->assertTrue($this->feedFor([
			'platform' => Platform::Google->value,
		])->validate());
	}

	/**
	 * The reproduction for the original 500: a bad platform with a populated mapping. The mapping
	 * validator resolves the platform through `Platform::from()`, so if it runs first it throws instead
	 * of letting the `in` rule report the error.
	 */
	public function testAnUnknownPlatformIsAValidationErrorNotAFatal(): void
	{
		$feed = $this->feedFor([
			'platform' => 'bogus',
		]);

		$this->assertFalse($feed->validate());
		$this->assertArrayHasKey('platform', $feed->getErrors());
	}

	public function testAnUnknownSourceIsAValidationErrorNotAFatal(): void
	{
		$feed = $this->feedFor([
			'source' => 'bogus',
		]);

		$this->assertFalse($feed->validate());
		$this->assertArrayHasKey('source', $feed->getErrors());
	}

	/**
	 * `name`/`handle` are `varchar(255)` and `imageWidth`/`imageHeight` are `smallInteger()` unsigned,
	 * capped at 32767 on Postgres. Without these rules an out-of-range value passes validation and then
	 * raises an uncaught DB exception at `save(false)`.
	 */
	public function testOversizeStringsAreRejected(): void
	{
		$feed = $this->feedFor([
			'name' => str_repeat('a', 300),
		]);

		$this->assertFalse($feed->validate());
		$this->assertArrayHasKey('name', $feed->getErrors());
	}

	public function testOutOfRangeImageDimensionsAreRejected(): void
	{
		$tooWide = $this->feedFor([
			'imageWidth' => 99999,
		]);
		$this->assertFalse($tooWide->validate());
		$this->assertArrayHasKey('imageWidth', $tooWide->getErrors());

		$negative = $this->feedFor([
			'imageHeight' => -1,
		]);
		$this->assertFalse($negative->validate());
		$this->assertArrayHasKey('imageHeight', $negative->getErrors());
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private function feedFor(array $attributes): Feed
	{
		return new Feed([
			'name' => 'Validation',
			'handle' => self::HANDLE_PREFIX . 'Validation',
			'siteId' => $this->primarySiteId(),
			'token' => str_repeat('a', Feed::TOKEN_LENGTH),
			'fieldMapping' => [
				'title' => [
					'source' => Mapping::build(Mapping::ELEMENT, 'product.title'),
					'default' => '',
				],
			],
			...$attributes,
		]);
	}
}
