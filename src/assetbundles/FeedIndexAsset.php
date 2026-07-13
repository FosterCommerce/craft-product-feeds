<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\admintable\AdminTableAsset;

class FeedIndexAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			ProductFeedsAsset::class,
			AdminTableAsset::class,
		];

		$this->js = [
			'js/feed-index.js',
		];

		parent::init();
	}
}
