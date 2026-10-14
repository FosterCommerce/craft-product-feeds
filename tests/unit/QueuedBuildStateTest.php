<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\models\QueuedBuildState;
use PHPUnit\Framework\TestCase;

class QueuedBuildStateTest extends TestCase
{
	/**
	 * Each queue run reads the state the previous run saved, so a value that changes on the way through
	 * the filesystem changes the build.
	 */
	public function testTheStateReadsBackAsItWasSaved(): void
	{
		$state = new QueuedBuildState(
			[[11, 12], [13]],
			1,
			[
				'SKU-1' => true,
				'425' => true,
			],
			[
				'skippedByAttribute' => [
					'image_link' => 2,
				],
			],
		);

		$readBack = QueuedBuildState::fromJson($state->toJson());

		$this->assertSame([[11, 12], [13]], $readBack->elementIdBatches);
		$this->assertSame(1, $readBack->nextBatch);
		$this->assertSame([
			'image_link' => 2,
		], $readBack->diagnostics['skippedByAttribute']);
		$this->assertArrayHasKey('SKU-1', $readBack->publishedIds);
		// A numeric id comes back as an integer key, which `isset()` still finds by its string form.
		$this->assertTrue(isset($readBack->publishedIds['425']));
	}

	public function testABuildHasMoreBatchesUntilItsLastOneIsBuilt(): void
	{
		$state = new QueuedBuildState([[1], [2]]);
		$this->assertTrue($state->hasMoreBatches());

		$state->nextBatch = 2;
		$this->assertFalse($state->hasMoreBatches());
	}

	/**
	 * A catalog with no live elements still publishes an empty feed, with no batch to build first.
	 */
	public function testAnEmptyCatalogHasNoBatches(): void
	{
		$this->assertFalse((new QueuedBuildState([]))->hasMoreBatches());
	}
}
