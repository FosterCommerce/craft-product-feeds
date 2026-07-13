<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\models;

use fostercommerce\productfeeds\enums\ImageEngine;
use fostercommerce\productfeeds\enums\ImageFit;

final readonly class ImageTransform
{
	public function __construct(
		public ImageEngine $imageEngine,
		public ?string $namedTransform,
		public ?int $width,
		public ?int $height,
		public ImageFit $imageFit,
	) {
	}

	public static function fromFeed(Feed $feed): self
	{
		return new self(
			ImageEngine::from($feed->imageEngine),
			$feed->imageTransform,
			$feed->imageWidth,
			$feed->imageHeight,
			ImageFit::from($feed->imageFit),
		);
	}

	public function hasNamedTransform(): bool
	{
		return $this->namedTransform !== null && $this->namedTransform !== '';
	}

	/**
	 * Craft, Imager X and Small Pics all accept a width or a height alone.
	 */
	public function hasSize(): bool
	{
		return $this->width !== null || $this->height !== null;
	}

	/**
	 * @return array{width?: int, height?: int, mode: string}
	 */
	public function toConfig(): array
	{
		$config = [
			'mode' => $this->imageFit->value,
		];

		if ($this->width !== null) {
			$config['width'] = $this->width;
		}

		if ($this->height !== null) {
			$config['height'] = $this->height;
		}

		return $config;
	}
}
