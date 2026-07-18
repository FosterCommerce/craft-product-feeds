<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

/**
 * What a build left out, and what came through blank or unusable. The mapping screen reads it back.
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
	 * @var array<string, int>
	 */
	public array $skippedByAttribute = [];

	/**
	 * @var array<string, int>
	 */
	public array $blankByAttribute = [];

	/**
	 * @var array<string, int>
	 */
	public array $invalidByAttribute = [];

	/**
	 * @var array<string, int>
	 */
	public array $relativeUrlByAttribute = [];

	/**
	 * @var list<array{id: int, reason: string}>
	 */
	public array $sampleSkipped = [];

	/**
	 * @var array<string, string>
	 */
	public array $sampleRelativeUrls = [];

	public ?UrlCheck $urlCheck = null;

	public function countSkipped(string $attribute): void
	{
		$this->skippedByAttribute[$attribute] = ($this->skippedByAttribute[$attribute] ?? 0) + 1;
	}

	public function countBlank(string $attribute): void
	{
		$this->blankByAttribute[$attribute] = ($this->blankByAttribute[$attribute] ?? 0) + 1;
	}

	public function countInvalid(string $attribute): void
	{
		$this->invalidByAttribute[$attribute] = ($this->invalidByAttribute[$attribute] ?? 0) + 1;
	}

	public function countRelativeUrl(string $attribute, string $url): void
	{
		$this->relativeUrlByAttribute[$attribute] = ($this->relativeUrlByAttribute[$attribute] ?? 0) + 1;
		$this->sampleRelativeUrls[$attribute] ??= $url;
	}

	public function recordSkippedSample(int $elementId, string $reason): void
	{
		if (count($this->sampleSkipped) < self::SAMPLE_LIMIT) {
			$this->sampleSkipped[] = [
				'id' => $elementId,
				'reason' => $reason,
			];
		}
	}

	public function skippedCount(): int
	{
		return array_sum($this->skippedByAttribute);
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	public static function fromArray(array $stored): self
	{
		$diagnostics = new self();

		/** @var array<string, int> $skippedByAttribute */
		$skippedByAttribute = is_array($stored['skippedByAttribute'] ?? null) ? $stored['skippedByAttribute'] : [];
		/** @var array<string, int> $blankByAttribute */
		$blankByAttribute = is_array($stored['blankByAttribute'] ?? null) ? $stored['blankByAttribute'] : [];
		/** @var array<string, int> $invalidByAttribute */
		$invalidByAttribute = is_array($stored['invalidByAttribute'] ?? null) ? $stored['invalidByAttribute'] : [];
		/** @var array<string, int> $relativeUrlByAttribute */
		$relativeUrlByAttribute = is_array($stored['relativeUrlByAttribute'] ?? null) ? $stored['relativeUrlByAttribute'] : [];
		/** @var list<array{id: int, reason: string}> $sampleSkipped */
		$sampleSkipped = is_array($stored['sampleSkipped'] ?? null) ? array_values($stored['sampleSkipped']) : [];
		/** @var array<string, string> $sampleRelativeUrls */
		$sampleRelativeUrls = is_array($stored['sampleRelativeUrls'] ?? null) ? $stored['sampleRelativeUrls'] : [];

		$diagnostics->skippedByAttribute = $skippedByAttribute;
		$diagnostics->blankByAttribute = $blankByAttribute;
		$diagnostics->invalidByAttribute = $invalidByAttribute;
		$diagnostics->relativeUrlByAttribute = $relativeUrlByAttribute;
		$diagnostics->sampleSkipped = $sampleSkipped;
		$diagnostics->sampleRelativeUrls = $sampleRelativeUrls;

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
			'skippedByAttribute' => $this->skippedByAttribute,
			'blankByAttribute' => $this->blankByAttribute,
			'invalidByAttribute' => $this->invalidByAttribute,
			'relativeUrlByAttribute' => $this->relativeUrlByAttribute,
			'sampleSkipped' => $this->sampleSkipped,
			'sampleRelativeUrls' => $this->sampleRelativeUrls,
			'urlCheck' => $this->urlCheck?->toArray(),
		];
	}
}
