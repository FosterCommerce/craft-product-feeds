<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

final readonly class BuildResult
{
	/**
	 * @param int $bytes size of the gzipped artifact
	 * @param int|null $bytesUncompressed what the inflated route serves, null where the gzip trailer
	 * could not be read
	 */
	public function __construct(
		public int $itemCount,
		public int $bytes,
		public ?int $bytesUncompressed,
		public BuildDiagnostics $buildDiagnostics,
	) {
	}

	public function skippedCount(): int
	{
		return $this->buildDiagnostics->skippedCount();
	}
}
