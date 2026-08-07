# Upgrade notes

Breaking changes and the action each one requires, newest first. A release that
needs nothing from you is not listed here; see `CHANGELOG.md` for the full
record, and `readme.txt` for the site owner facing summary.

## 1.2.0

Nothing in this plugin's own PHP API changed signature in 1.2.0, and no option
key was removed or renamed. A site that installs the zip, uses the shortcode and
the settings screen, and writes no custom PHP against Scolta has nothing to do
beyond updating.

### Inherited from scolta-php 1.2.0

This release bundles `tag1/scolta-php` `^1.2.0`, up from the 1.1 line. That
library carries two breaking changes:

- **The `AmazeeCredentials` constructor signature changed.**
- **The `aiProvider` default changed.**

The plugin vendors scolta-php into the archive it ships, so the bundled library
is whatever the lock names. Neither type is re-exported through this plugin's
hooks, filters, REST routes or WP-CLI commands, so a site is only exposed if it
has custom PHP calling into the `Tag1\Scolta\` namespace directly. See
scolta-php's 1.2.0 upgrade notes for the detail and the required changes.

### No AI provider is selected on activation

Not a break in the signature sense, but it changes what a fresh activation does.
The plugin used to seed a provider and coalesce an empty value back to
`anthropic`, so a site nobody had configured presented itself as an Anthropic
site and an API key set before anybody chose a provider read as a working
configuration. Activation now seeds no provider, the select opens on a
placeholder, and the status line says no provider is selected and AI features
are off.

This is going-forward only. There is no migration and no existing option is
touched: a site that already saved a provider keeps it and keeps working. Only
a fresh activation, and the empty-value coalescing, behave differently. If you
were relying on the implicit default without ever selecting a provider, select
one in Settings > Scolta to restore AI features.

### Facet counts changed value

Counts after AI query expansion will read lower than they did in 1.1.x, and some
values will now show as zero. This is the fix, not a regression: the count is
now computed from the same collapsed result set the list displays, and a value
carried by no visible result is zeroed rather than offered as a click through to
an empty page. If you have automated checks asserting exact facet counts against
an expanded query, re-baseline them.
