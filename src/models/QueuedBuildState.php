<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

use craft\helpers\Json;

/**
 * How far a queued build has got, saved on the feeds filesystem between its queue runs.
 *
 * The queue job only carries the build's ID, so the payload Craft stores for each run stays small however
 * large the catalog is.
 */
final class QueuedBuildState
{
	/**
	 * @param list<list<int>> $elementIdBatches the catalog as it stood when the build started
	 * @param array<array-key, true> $publishedIds item ids already written, so a later batch can exclude a duplicate
	 * @param array<string, mixed> $diagnostics `BuildDiagnostics::toArray()`
	 */
	public function __construct(
		public array $elementIdBatches,
		public int $nextBatch = 0,
		public array $publishedIds = [],
		public array $diagnostics = [],
	) {
	}

	public function hasMoreBatches(): bool
	{
		return $this->nextBatch < count($this->elementIdBatches);
	}

	public static function fromJson(string $json): self
	{
		/** @var array{elementIdBatches: list<list<int>>, nextBatch: int, publishedIds: array<array-key, true>, diagnostics: array<string, mixed>} $stored */
		$stored = Json::decode($json);

		return new self(
			$stored['elementIdBatches'],
			$stored['nextBatch'],
			$stored['publishedIds'],
			$stored['diagnostics'],
		);
	}

	public function toJson(): string
	{
		return Json::encode([
			'elementIdBatches' => $this->elementIdBatches,
			'nextBatch' => $this->nextBatch,
			'publishedIds' => $this->publishedIds,
			'diagnostics' => $this->diagnostics,
		], JSON_INVALID_UTF8_SUBSTITUTE);
	}
}
