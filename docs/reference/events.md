# Events

## ProductFeeds::EVENT_REGISTER_SOURCES

**Fires:** whenever the plugin looks up registered sources, including for the **Source** menu, the feed index, feed validation, and every load of a feed on a custom source. Expect several calls per request, so avoid queries in the listener.

**Payload:** `craft\events\RegisterComponentTypesEvent`

| Property | Type | Notes |
|---|---|---|
| `types` | `list<class-string<CustomSource>>` | Empty by default. Add a class that extends `fostercommerce\productfeeds\sources\CustomSource` |

**Listen:**

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

**Common use:** adding a source that publishes one item per size, finish, or other option an element offers. To write the class, see [add a custom source](../dev-guide/custom-sources.md).
