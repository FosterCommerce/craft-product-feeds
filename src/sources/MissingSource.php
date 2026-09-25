<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\sources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use fostercommerce\productfeeds\errors\FeedBuildException;
use fostercommerce\productfeeds\ProductFeeds;

/**
 * Stands in for a custom source no module or plugin registers, so the feed's edit screen still renders.
 */
final class MissingSource extends CustomSource
{
	public static function handle(): string
	{
		return '';
	}

	public static function displayName(): string
	{
		return '';
	}

	/**
	 * @throws FeedBuildException
	 */
	public function query(): ElementQueryInterface
	{
		throw new FeedBuildException($this->errorMessage());
	}

	public function errorMessage(): string
	{
		return Craft::t(ProductFeeds::HANDLE, 'error.sourceMissing', [
			'source' => $this->feed->source,
		]);
	}

	public function twigVariables(): array
	{
		return [];
	}

	public function computedAttributes(): array
	{
		return [];
	}

	public function fieldLayouts(): array
	{
		return [];
	}

	public function elementType(): string
	{
		return Entry::class;
	}

	public function handles(ElementInterface $element): bool
	{
		return false;
	}

	public function contains(ElementInterface $element): bool
	{
		return false;
	}
}
