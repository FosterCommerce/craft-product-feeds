# Product Feeds documentation

Builds product feeds from Craft Commerce variants or Craft entries, and serves each one from a stable URL that a shopping platform fetches on a schedule.

You create a feed, pick the platform it is for, choose which of the two sources it reads, and map each attribute the platform asks for to your Craft data. You paste the feed's URL into the platform once, and the plugin updates the dynamic data as it changes to keep the feed up-to-date.

## Where to go

**Setting it up?** Start with [Installation](./installation.md), which covers the filesystem, the settings, and scheduling the build.

**Running feeds day-to-day?** See the user guide:

- [Mapping a feed](./user-guide/mapping.md), how to connect Craft data to platform attributes
- [Troubleshooting](./user-guide/troubleshooting.md), why a feed failed, or came out thin

**Looking something up?**

- [Attributes](./reference/attributes.md), which attributes exist, which are required, and which the plugin derives
- [Schema](./reference/schema.md), the table the plugin installs and what a build records on it
- [Permissions](./reference/permissions.md), what each permission grants
