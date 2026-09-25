<?php

declare(strict_types=1);

namespace fostercommerce\productfeeds\tests\integration\fixtures;

use craft\base\ElementInterface;
use craft\commerce\elements\Variant;
use craft\elements\db\ElementQueryInterface;
use fostercommerce\productfeeds\sources\CustomSource;
use fostercommerce\productfeeds\sources\SuppliedItem;

/**
 * A custom source over the install's enabled variants. Each test sets its items through `$itemsFor`.
 */
class TestItemsSource extends CustomSource
{
	/**
	 * @var (callable(ElementInterface): list<SuppliedItem>)|null
	 */
	public static $itemsFor;

	public static function handle(): string
	{
		return 'pfTestItems';
	}

	public static function displayName(): string
	{
		return 'Test items';
	}

	public function query(): ElementQueryInterface
	{
		return Variant::find()
			->siteId($this->feed->siteId)
			->status(Variant::STATUS_ENABLED)
			->orderBy([
				'elements.id' => SORT_ASC,
			]);
	}

	public function computedAttributes(): array
	{
		return ['id', 'item_group_id', 'title', 'link', 'price', 'image_link'];
	}

	public function items(ElementInterface $element): array
	{
		return self::$itemsFor === null ? [new SuppliedItem()] : (self::$itemsFor)($element);
	}

	public function fieldLayouts(): array
	{
		return [];
	}

	public function elementType(): string
	{
		return Variant::class;
	}

	public function handles(ElementInterface $element): bool
	{
		return $element instanceof Variant;
	}

	public function contains(ElementInterface $element): bool
	{
		return false;
	}
}
