<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\unit;

use fostercommerce\productfeeds\enums\ImageEngine;
use fostercommerce\productfeeds\enums\ImageFit;
use fostercommerce\productfeeds\models\ImageTransform;
use PHPUnit\Framework\TestCase;

class ImageTransformTest extends TestCase
{
	public function testConfigCarriesSizeAndMode(): void
	{
		$transform = new ImageTransform(ImageEngine::ImagerX, null, 500, 400, ImageFit::Fit);

		$this->assertSame([
			'mode' => 'fit',
			'width' => 500,
			'height' => 400,
		], $transform->toConfig());
	}

	public function testConfigDefaultsToCrop(): void
	{
		$transform = new ImageTransform(ImageEngine::Craft, null, null, null, ImageFit::Crop);

		$this->assertSame([
			'mode' => 'crop',
		], $transform->toConfig());
	}

	public function testDistinguishesNamedFromSize(): void
	{
		$named = new ImageTransform(ImageEngine::Craft, 'square600', null, null, ImageFit::Crop);
		$this->assertTrue($named->hasNamedTransform());
		$this->assertFalse($named->hasSize());

		$sized = new ImageTransform(ImageEngine::Craft, '', 500, 500, ImageFit::Crop);
		$this->assertFalse($sized->hasNamedTransform());
		$this->assertTrue($sized->hasSize());
	}

	/**
	 * One dimension is a transform Craft accepts. Requiring both would leave the Craft engine
	 * publishing the untransformed original while the plugin engines honoured the width.
	 */
	public function testASingleDimensionIsASize(): void
	{
		$widthOnly = new ImageTransform(ImageEngine::Craft, null, 800, null, ImageFit::Crop);
		$this->assertTrue($widthOnly->hasSize());
		$this->assertSame([
			'mode' => 'crop',
			'width' => 800,
		], $widthOnly->toConfig());

		$heightOnly = new ImageTransform(ImageEngine::Craft, null, null, 800, ImageFit::Crop);
		$this->assertTrue($heightOnly->hasSize());
		$this->assertSame([
			'mode' => 'crop',
			'height' => 800,
		], $heightOnly->toConfig());
	}
}
