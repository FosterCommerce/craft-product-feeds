# Permissions

Registered under the **Product Feeds** heading in a user group's permissions. `productFeeds:edit` and `productFeeds:build` are nested under `productFeeds:view`.

| Handle | Description |
|---|---|
| `productFeeds:view` | See feeds, their mapping, their data issues, and the feed URL. |
| `productFeeds:edit` | Create, edit, duplicate, reorder, and delete feeds, and rotate a feed's URL. |
| `productFeeds:build` | Build a feed now, preview one, and test an image. |

A user with only `productFeeds:view` sees the feed screen read-only, with a notice saying so.

On a multi-site install, a feed is also gated on the site it belongs to: a user needs `editSite:<uid>` for that site. Single-site installs never grant `editSite`, so it is not checked there.

Plugin settings are separate, and remain admin-only.
