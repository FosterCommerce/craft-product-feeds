# Permissions

Registered under the **Product Feeds** heading in a user group's permissions. `productFeeds:edit` and `productFeeds:build` are nested under `productFeeds:view`.

| Handle | Description |
|---|---|
| `productFeeds:view` | See feeds, their mapping, their last build results, the feed URL, and the excluded items CSV. |
| `productFeeds:edit` | Create, edit, duplicate, reorder, and delete feeds, rotate a feed's URL, and write Twig values. |
| `productFeeds:build` | Build a feed now, preview one, and test an image. Without `productFeeds:edit`, the image test uses the saved mapping. |

A user with only `productFeeds:view` sees the feed screen read-only, with a notice saying so.

On a multi-site install, a user also needs `editSite:<uid>` for the feed's site. The plugin does not check `editSite` on a single-site install.

Plugin settings are admin-only.

A Twig value can read the site's configuration unless the site turns on Craft's `enableTwigSandbox`. Grant `productFeeds:edit` only to people you trust with the site's configuration. For details, see [Twig and the sandbox](../user-guide/mapping.md#twig-and-the-sandbox).
