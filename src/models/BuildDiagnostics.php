<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

/**
 * What a build noticed while it ran: what it left out, and what came through blank or unusable. The
 * mapping screen reads this back to tell the admin which rows are pulling their weight.
 *
 * Written as the build streams, so it is mutable.
 */
final class BuildDiagnostics
{
	/**
	 * Enough to spot a pattern in the CP without carrying the whole catalog in a JSON column. The CSV
	 * report is the full list.
	 */
	private const SAMPLE_LIMIT = 50;

	/**
	 * @var array<string, int> attribute that was required and blank => items it excluded
	 */
	public array $skippedByReason = [];

	/**
	 * @var array<string, int> mapped attribute => items its source produced nothing for
	 */
	public array $blankByAttribute = [];

	/**
	 * @var array<string, int> money attribute => items priced at zero or less
	 */
	public array $invalidByAttribute = [];

	/**
	 * @var list<array{id: int, reason: string}>
	 */
	public array $sampleSkipped = [];

	public ?UrlCheck $urlCheck = null;

	public function countSkipped(string $attribute): void
	{
		$this->skippedByReason[$attribute] = ($this->skippedByReason[$attribute] ?? 0) + 1;
	}

	public function countBlank(string $attribute): void
	{
		$this->blankByAttribute[$attribute] = ($this->blankByAttribute[$attribute] ?? 0) + 1;
	}

	public function countInvalid(string $attribute): void
	{
		$this->invalidByAttribute[$attribute] = ($this->invalidByAttribute[$attribute] ?? 0) + 1;
	}

	public function sampleSkipped(int $elementId, string $attribute): void
	{
		if (count($this->sampleSkipped) < self::SAMPLE_LIMIT) {
			$this->sampleSkipped[] = [
				'id' => $elementId,
				'reason' => $attribute,
			];
		}
	}

	public function skippedCount(): int
	{
		return array_sum($this->skippedByReason);
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	public static function fromArray(array $stored): self
	{
		$diagnostics = new self();

		/** @var array<string, int> $skippedByReason */
		$skippedByReason = is_array($stored['skippedByReason'] ?? null) ? $stored['skippedByReason'] : [];
		/** @var array<string, int> $blankByAttribute */
		$blankByAttribute = is_array($stored['blankByAttribute'] ?? null) ? $stored['blankByAttribute'] : [];
		/** @var array<string, int> $invalidByAttribute */
		$invalidByAttribute = is_array($stored['invalidByAttribute'] ?? null) ? $stored['invalidByAttribute'] : [];
		/** @var list<array{id: int, reason: string}> $sampleSkipped */
		$sampleSkipped = is_array($stored['sampleSkipped'] ?? null) ? array_values($stored['sampleSkipped']) : [];

		$diagnostics->skippedByReason = $skippedByReason;
		$diagnostics->blankByAttribute = $blankByAttribute;
		$diagnostics->invalidByAttribute = $invalidByAttribute;
		$diagnostics->sampleSkipped = $sampleSkipped;

		$urlCheck = $stored['urlCheck'] ?? null;
		$diagnostics->urlCheck = is_array($urlCheck) ? UrlCheck::fromArray($urlCheck) : null;

		return $diagnostics;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'skippedByReason' => $this->skippedByReason,
			'blankByAttribute' => $this->blankByAttribute,
			'invalidByAttribute' => $this->invalidByAttribute,
			'sampleSkipped' => $this->sampleSkipped,
			'urlCheck' => $this->urlCheck?->toArray(),
		];
	}
}
