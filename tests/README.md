# Tests

Two suites.

## Unit

Pure PHP, no Craft. Covers the feed specs, value normalisation, mapping, the image transform, and the RSS writer.

```sh
composer test
```

## Integration

Boots a real Craft install and exercises the parts that need one: the build pipeline end to end, the auto-rebuild triggers, the queue's pending and dirty flags, and the source queries.

It needs a working Craft + Commerce install with the plugin enabled. Point `CRAFT_BASE_PATH` at it, and run the suite from the plugin directory:

```sh
CRAFT_BASE_PATH=/path/to/craft composer test:integration
```

Under DDEV, run it inside the container:

```sh
ddev exec bash -c "cd plugins/fostercommerce/product-feeds && CRAFT_BASE_PATH=/var/www/html composer test:integration"
```

Without `CRAFT_BASE_PATH` every test in the suite skips itself, so `composer test` stays runnable anywhere.

### What it writes

The suite creates feeds handled `pfTest*`, and deletes them, along with any build they queued, after each test. It builds against whatever catalog the install has, and writes each artifact to the plugin's configured filesystem under `product-feeds/`. A test skips itself rather than failing when the install has nothing to build from: no image assets, no product type with URLs, no live variants.
