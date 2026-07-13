# Schema

The plugin installs one table. Source of truth is `src/migrations/Install.php`.

Feeds are store-admin content rather than project config, so they live only in the database and are not written to `project.yaml`. Uninstalling the plugin drops the table.

## productfeeds_feeds

One row per feed. A feed belongs to one site, and its build history lives on the same row: there is no separate history table, because only the last build is ever read.

| Column | Type | Notes |
|---|---|---|
| `id` | integer | Primary key. |
| `name` | string | What the admin called the feed. |
| `handle` | string | Unique per site, so the same handle can run on each of a store's sites. Appears in the feed URL. |
| `platform` | string(16) | `google`, `meta`, `microsoft`, `pinterest`, or `tiktok`. |
| `source` | string(16) | `variants` or `entries`. |
| `siteId` | integer | Foreign key to `sites.id`, `ON DELETE CASCADE`. Indexed. |
| `sourceIds` | text | JSON. Product type IDs for a variant feed, `sectionId:entryTypeId` pairs for an entry feed. Empty means every source whose items have a public URL. |
| `fieldMapping` | mediumtext | JSON, keyed by attribute: `{"source": "...", "default": "..."}`. MEDIUMTEXT because 64KB is not enough headroom for a full mapping. |
| `filterCondition` | mediumtext | JSON. A serialized `ElementCondition` config, applied to the source query. |
| `imageEngine` | string | `none`, `craft`, `imagerx`, or `smallpics`. Default: `none`. |
| `imageTransform` | string | Handle of a named Craft transform. Null for a custom size. |
| `imageWidth` | smallint unsigned | Null when a named transform carries the size. |
| `imageHeight` | smallint unsigned | Null when a named transform carries the size. |
| `imageFit` | string | `crop` or `fit`. Default: `crop`. |
| `token` | string(32) | Unique, indexed. The only credential on the public feed route, so it comes from Craft's CSPRNG. Rotating it mints a new one and deletes the old artifact. |
| `enabled` | boolean | Default: `true`. Indexed, because the scheduler enqueues every enabled feed. |
| `sortOrder` | smallint unsigned | The order the feeds index lists them in. |
| `lastBuildStatus` | string(16) | `pending`, `building`, `ok`, or `failed`. Default: `pending`. |
| `lastBuildStartedAt` | datetime | UTC. A build stuck in `building` past the build timeout is treated as stalled and queued again. |
| `lastBuildFinishedAt` | datetime | UTC. What the build interval is measured from. |
| `lastBuildItemCount` | integer | Items the last build wrote to the feed. |
| `lastBuildSkippedCount` | integer | Items it excluded for a blank required attribute. |
| `lastBuildBytes` | bigint | Size of the gzipped artifact. The compressed route 404s until this is set. |
| `lastBuildBytesUncompressed` | bigint | What the `.xml` route serves. Read from the gzip trailer. |
| `lastBuildError` | text | The failure message, shown in the feeds index. |
| `lastBuildDiagnostics` | text | JSON. See below. |
| `dateCreated` | datetime | |
| `dateUpdated` | datetime | |
| `uid` | uid | |

### lastBuildDiagnostics

What the last build noticed, and what the mapping screen reads back. Shaped by `BuildDiagnostics`.

| Key | Type | Notes |
|---|---|---|
| `skippedByReason` | object | Required attribute that came out blank, to the number of items it excluded. |
| `blankByAttribute` | object | Mapped attribute, to the number of items its source produced nothing for. |
| `invalidByAttribute` | object | Money attribute, to the number of items priced at zero or less. Those items stay in the feed. |
| `sampleSkipped` | array | Up to 50 `{"id": 0, "reason": "..."}` entries, for the Excluded products panel. The CSV report on the feed filesystem carries the full set. |
| `urlCheck` | object | `{"status": 0, "contentType": "...", "error": null}` from the `HEAD` request sent after a successful build. Advisory. |

## Artifacts

Built feeds are not stored in the database. Each one is written to the plugin's configured filesystem:

| Path | Contents |
|---|---|
| `product-feeds/<token>.<extension>.gz` | The gzipped feed. Overwritten on each build rather than timestamped. |
| `product-feeds/<token>-excluded.csv` | The full list of excluded items. Deleted when a build excludes nothing. |

Both are named from the token, so rotating a feed's token orphans nothing: the old files are deleted first.
