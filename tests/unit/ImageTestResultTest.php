<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\models\ImageTestResult;
use PHPUnit\Framework\TestCase;

class ImageTestResultTest extends TestCase
{
	/**
	 * `feed-edit.js` shows the image count only when one came back, so a missing count must be null
	 * rather than zero.
	 */
	public function testTheImageCountIsNullWithoutACraftTransform(): void
	{
		$result = ImageTestResult::fetched(null, 'https://example.com/a.jpg', 200, 'image/jpeg', 10, 10);

		$this->assertNull($result->generatedImageCount);
		$this->assertNull($result->imageCount);
	}

	public function testAFetchedImageCarriesTheImageCount(): void
	{
		$result = ImageTestResult::fetched(null, 'https://example.com/a.jpg', 200, 'image/jpeg', 10, 10, [
			'generated' => 174,
			'total' => 2864,
		]);

		$this->assertSame(174, $result->generatedImageCount);
		$this->assertSame(2864, $result->imageCount);
	}

	/**
	 * A failed fetch still reports the count, since a missing transform is one reason the fetch fails.
	 */
	public function testAFailedFetchCarriesTheImageCount(): void
	{
		$result = ImageTestResult::failed(null, 'Timed out', 'https://example.com/a.jpg', [
			'generated' => 0,
			'total' => 3,
		]);

		$this->assertFalse($result->ok);
		$this->assertSame(0, $result->generatedImageCount);
		$this->assertSame(3, $result->imageCount);
	}
}
