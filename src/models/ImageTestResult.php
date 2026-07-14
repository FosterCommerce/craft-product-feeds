<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

/**
 * What fetching one item's image URL came back with.
 *
 * The platform's minimum size is carried even when the fetch failed, because the screen states it
 * either way.
 */
final readonly class ImageTestResult
{
	private function __construct(
		public bool $ok,
		public ?string $url,
		public ?int $status,
		public ?string $contentType,
		public ?int $width,
		public ?int $height,
		public bool $meetsMinimum,
		public ?int $minimumWidth,
		public ?int $minimumHeight,
		public ?string $error,
	) {
	}

	/**
	 * No image to fetch, or the fetch itself threw.
	 *
	 * @param array{0: int, 1: int}|null $minimumSize
	 */
	public static function failed(?array $minimumSize, string $error, ?string $url = null): self
	{
		return new self(
			ok: false,
			url: $url,
			status: null,
			contentType: null,
			width: null,
			height: null,
			meetsMinimum: false,
			minimumWidth: $minimumSize[0] ?? null,
			minimumHeight: $minimumSize[1] ?? null,
			error: $error,
		);
	}

	/**
	 * The image answered. A 404 page or an HTML error body is not a usable image.
	 *
	 * @param array{0: int, 1: int}|null $minimumSize
	 */
	public static function fetched(
		?array $minimumSize,
		string $url,
		int $status,
		?string $contentType,
		?int $width,
		?int $height,
	): self {
		$decoded = $width !== null && $height !== null;

		return new self(
			ok: $status === 200 && $decoded,
			url: $url,
			status: $status,
			contentType: $contentType,
			width: $width,
			height: $height,
			meetsMinimum: $minimumSize === null
				|| ($decoded && $width >= $minimumSize[0] && $height >= $minimumSize[1]),
			minimumWidth: $minimumSize[0] ?? null,
			minimumHeight: $minimumSize[1] ?? null,
			error: null,
		);
	}

	/**
	 * @return array{ok: bool, url: ?string, status: ?int, contentType: ?string, width: ?int, height: ?int, meetsMinimum: bool, minimumWidth: ?int, minimumHeight: ?int, error: ?string}
	 */
	public function toArray(): array
	{
		return [
			'ok' => $this->ok,
			'url' => $this->url,
			'status' => $this->status,
			'contentType' => $this->contentType,
			'width' => $this->width,
			'height' => $this->height,
			'meetsMinimum' => $this->meetsMinimum,
			'minimumWidth' => $this->minimumWidth,
			'minimumHeight' => $this->minimumHeight,
			'error' => $this->error,
		];
	}
}
