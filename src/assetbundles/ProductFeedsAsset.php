<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ProductFeedsAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			CpAsset::class,
		];

		$this->css = [
			'css/product-feeds.css',
		];

		parent::init();
	}
}
