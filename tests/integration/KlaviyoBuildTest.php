<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration;

use fostercommerce\productfeeds\enums\Platform;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\models\Feed;

class KlaviyoBuildTest extends IntegrationTestCase
{
	public function testAKlaviyoFeedIsAFlatGzippedJsonArray(): void
	{
		$feed = $this->klaviyoFeed('klaviyoJson');
		$result = $this->buildOrSkip($feed);

		$items = $this->publishedItems($feed);

		$this->assertCount($result->itemCount, $items);

		foreach ($items as $item) {
			foreach ($item as $value) {
				// Klaviyo reads a feed one node deep. `categories` is the one list, and it holds strings.
				$this->assertTrue(
					is_string($value) || is_int($value) || is_float($value) || is_array($value),
					'A published value is nested deeper than Klaviyo reads.'
				);

				if (is_array($value)) {
					$this->assertContainsOnly('string', $value);
				}
			}
		}
	}

	/**
	 * The build excludes an item whose required attribute is blank.
	 */
	public function testEveryPublishedItemCarriesTheFieldsKlaviyoRequires(): void
	{
		$feed = $this->klaviyoFeed('klaviyoRequired');
		$this->buildOrSkip($feed);

		foreach ($this->publishedItems($feed) as $item) {
			foreach (['$id', '$title', '$description', '$link', '$image_link'] as $field) {
				$this->assertNotSame('', $item[$field] ?? '', sprintf('A published item is missing “%s”.', $field));
			}
		}
	}

	/**
	 * Klaviyo takes the currency from the account, so the price is a bare JSON number rather than the
	 * decimal and currency code the shopping platforms take.
	 */
	public function testPricesArePublishedAsNumbersWithoutACurrencyCode(): void
	{
		$feed = $this->klaviyoFeed('klaviyoPrice');
		$this->buildOrSkip($feed);

		foreach ($this->publishedItems($feed) as $item) {
			$this->assertIsNumeric($item['$price'] ?? null);
			$this->assertIsNotString($item['$price']);
		}
	}

	/**
	 * Stock reaches Klaviyo as a number. An untracked variant has none, and a zero would read as out of
	 * stock, so the field is left off the item entirely.
	 */
	public function testStockIsPublishedAsANumberOrNotAtAll(): void
	{
		$feed = $this->klaviyoFeed('klaviyoStock');
		$this->buildOrSkip($feed);

		foreach ($this->publishedItems($feed) as $item) {
			if (array_key_exists('$inventory_quantity', $item)) {
				$this->assertIsNotString($item['$inventory_quantity']);
			}
		}
	}

	/**
	 * A Klaviyo feed. `inventory_policy` is the one field Klaviyo needs beyond the shared defaults.
	 */
	private function klaviyoFeed(string $handle): Feed
	{
		return $this->buildableFeed($handle, Platform::Klaviyo, [
			'inventory_policy' => [
				'source' => Mapping::USE_DEFAULT,
				'default' => '1',
			],
		]);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function publishedItems(Feed $feed): array
	{
		$items = json_decode($this->publishedArtifact($feed), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsList($items);

		/** @var list<array<string, mixed>> $items */
		return $items;
	}
}
