# Add a custom source

A custom source determines which elements a feed reads and which items each element publishes, such as one item for each size an entry offers. A module or plugin registers it, and store admins pick it from a feed's **Source** menu beside **Commerce variants** and **Entries**.

A feed on a custom source has no **Product types**, **Entry types**, or **Filter** settings, because the source picks its own elements. The feed's mapping, image engine, and image size still apply.

## The contract

Extend `fostercommerce\productfeeds\sources\CustomSource` and implement these methods:

| Method | Returns |
|---|---|
| `static handle()` | A unique handle, not `variants` or `entries`. A handle already in use throws an exception wherever the plugin lists sources. The feed stores the handle, so do not change it once a feed uses the source |
| `static displayName()` | The label in the **Source** menu |
| `query()` | An element query for the elements the feed reads, scoped to `$this->feed->siteId`. Limit the query to live elements; the plugin publishes every element the query returns |
| `elementType()` | The class of those elements |
| `fieldLayouts()` | The field layouts the **Mapping** tab lists fields from, keyed by `Mapping::FIELD` |
| `computedAttributes()` | The attribute handles `items()` supplies. The **Mapping** tab shows these as set by the source, with no dropdown. A required attribute left off this list must be mapped, or the build fails |
| `handles($element)` | Whether an element is of a type the source reads. Saving or deleting one rebuilds the feed. See [rebuilds](#rebuilds) |
| `contains($element)` | Whether an element belongs to the source, at any status. Creating an element the source contains rebuilds the feed |

To publish more than one item per element, override `items($element)`. It returns one `SuppliedItem` per item. Each `SuppliedItem` has `values` keyed by attribute handle:

- A value in the array replaces the feed's mapping for that attribute. Attributes the array leaves out come from the mapping. An attribute `computedAttributes()` lists is left blank instead. A key with an empty value leaves the attribute blank.
- The plugin cleans up a supplied value the same way as a mapped one: it strips markup and truncates text to the attribute's limit.
- Keys are the handles in the [attributes reference](../reference/attributes.md), such as `id`, `title`, and `price`. The plugin ignores a key the platform does not define, and a key the plugin derives, such as `identifier_exists`.
- Supply `price` and `sale_price` as decimal strings, such as `"24.00"`. The feed publishes them in the store's currency, and a non-numeric value leaves the attribute blank.
- Supply an image attribute as an `Asset`. The plugin transforms it with the feed's image engine. The plugin uses a string image value as the URL, resolving a relative URL against the site's domain. A string with a scheme other than `http` or `https` is discarded.
- A `link` value must be an absolute `http` or `https` URL, or the plugin discards it. To give each item its own URL, supply `link` in `items()`. The **Mapping** tab offers only the element's URL, which all of the element's items would share.

An element whose `items()` returns an empty array does not publish an item, and the excluded items CSV does not list the element.

A `SuppliedItem`'s `variables` are available to the feed's [Twig values](../user-guide/mapping.md#twig-values), beside `object` (the element) and `item` (the item's `values`). Pass what a template needs to tell one item from another, such as the row a size came from. With Craft's Twig sandbox on, a template can read an element's fields, Craft's own properties, and any array key. Pass arrays or scalars, not models.

The info icon beside each Twig value lists these variables. The plugin reads them from the first item of the source's first element, and shows an element by its type and an array by its keys.

## Minimal example

This source publishes one item for each row of a `sizes` Table field on entries in a `posters` section. The Table field has a `size` column (Small, Medium, Large) and a `price` column.

```php
<?php

declare(strict_types=1);

namespace modules\feeds;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use fostercommerce\productfeeds\helpers\Mapping;
use fostercommerce\productfeeds\sources\CustomSource;
use fostercommerce\productfeeds\sources\SuppliedItem;

class PosterSizesSource extends CustomSource
{
	public static function handle(): string
	{
		return 'posterSizes';
	}

	public static function displayName(): string
	{
		return 'Poster sizes';
	}

	public function query(): ElementQueryInterface
	{
		return $this->posters()
			->status(Entry::STATUS_LIVE)
			->with($this->eagerLoadPaths());
	}

	public function elementType(): string
	{
		return Entry::class;
	}

	public function fieldLayouts(): array
	{
		$fieldLayouts = [];

		foreach (Craft::$app->getEntries()->getSectionByHandle('posters')?->getEntryTypes() ?? [] as $entryType) {
			$fieldLayouts[] = $entryType->getFieldLayout();
		}

		return [
			Mapping::FIELD => $fieldLayouts,
		];
	}

	public function computedAttributes(): array
	{
		return ['id', 'item_group_id', 'title', 'link', 'size', 'price'];
	}

	public function handles(ElementInterface $element): bool
	{
		return $element instanceof Entry && $element->getSection()?->handle === 'posters';
	}

	public function contains(ElementInterface $element): bool
	{
		return $this->handles($element) && $this->posters()->status(null)->id($element->id)->exists();
	}

	public function items(ElementInterface $element): array
	{
		$items = [];

		foreach ($element->getFieldValue('sizes') ?? [] as $row) {
			$items[] = new SuppliedItem(
				[
					'id' => $element->id . '-' . $row['size'],
					'item_group_id' => (string) $element->id,
					'title' => $element->title . ' (' . $row['size'] . ')',
					'link' => $element->getUrl(),
					'size' => $row['size'],
					'price' => (string) $row['price'],
				],
				[
					'sizeRow' => $row,
				],
			);
		}

		return $items;
	}

	private function posters(): EntryQuery
	{
		return Entry::find()
			->section('posters')
			->siteId($this->feed->siteId);
	}
}
```

All of a poster's items share an `item_group_id`, so the platform lists them as sizes of one product. Every other attribute, such as `description`, `image_link`, and `availability`, comes from the feed's mapping.

## Register it

Add the class to `ProductFeeds::EVENT_REGISTER_SOURCES` in your module's `init()`:

```php
use craft\events\RegisterComponentTypesEvent;
use fostercommerce\productfeeds\ProductFeeds;
use modules\feeds\PosterSizesSource;
use yii\base\Event;

Event::on(
	ProductFeeds::class,
	ProductFeeds::EVENT_REGISTER_SOURCES,
	static function (RegisterComponentTypesEvent $event): void {
		$event->types[] = PosterSizesSource::class;
	}
);
```

## Rebuilds

When **Rebuild when products change** is on, saving an element that `handles()` accepts rebuilds the feed. Only entries, products, and variants trigger a rebuild. A source that reads another element type, such as categories or assets, rebuilds on the schedule.

## When the source is missing

If no installed module or plugin registers a feed's source, each build of that feed fails with this error:

> This feed reads from the “posterSizes” source, which no installed module or plugin provides.

The feed's edit screen shows the error on the **Source** field, and lists the source as "posterSizes (not available)". **Preview items** and **Test image** show the same message. To save the feed, pick another source.

## Excluded items

The plugin checks each item on its own:

- An item without a required attribute is left out of the feed and listed in the excluded items CSV.
- If two items share an `id`, the plugin publishes the first and lists the second as "duplicate id".

In the CSV, each row shows the item's own `id`, so you can tell which of an element's items the plugin left out.
