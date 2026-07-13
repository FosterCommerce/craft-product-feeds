<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

/**
 * What a HEAD request to the published feed URL came back with. Advisory: a queue worker frequently
 * cannot resolve its own site's public hostname, so this never fails a build.
 */
final readonly class UrlCheck
{
	public function __construct(
		public ?int $status = null,
		public ?string $contentType = null,
		public ?string $error = null,
	) {
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	public static function fromArray(array $stored): self
	{
		$status = $stored['status'] ?? null;
		$contentType = $stored['contentType'] ?? null;
		$error = $stored['error'] ?? null;

		return new self(
			is_numeric($status) ? (int) $status : null,
			is_string($contentType) ? $contentType : null,
			is_string($error) ? $error : null,
		);
	}

	/**
	 * @return array{status: ?int, contentType: ?string, error: ?string}
	 */
	public function toArray(): array
	{
		return [
			'status' => $this->status,
			'contentType' => $this->contentType,
			'error' => $this->error,
		];
	}
}
