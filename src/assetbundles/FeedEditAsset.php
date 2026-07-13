<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\assetbundles;

use craft\web\AssetBundle;

class FeedEditAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			ProductFeedsAsset::class,
		];

		$this->js = [
			'js/feed-edit.js',
		];

		parent::init();
	}
}
