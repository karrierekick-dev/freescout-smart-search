# Smart Search

Enhanced unified conversation search for FreeScout.

**Version:** 1.0.0  
**License:** AGPL-3.0  
**Repository:** [karrierekick-dev/freescout-better-search](https://github.com/karrierekick-dev/freescout-better-search)

## Attribution

This module is a further development of the wonderful
[Adam Smart Search UI](https://github.com/AdamCoffeeOverflow/freescout-Adam-Smart-SearchUI)
by [AdamCoffeeOverflow](https://github.com/AdamCoffeeOverflow).

Many thanks to Adam for the original module — this fork builds on that foundation
with additional filters, bulk actions, and UI refinements. License remains AGPL-3.0.

## Features

### Search

- Always-visible navbar search with permission-safe suggestions
- Dedicated Smart Search page (opens idle — search only after submit)
- Subject, preview, customer email, customer name, phone, message, reply, and internal-note search
- Exact numeric resolution:
  - `#1234` prefers FreeScout's display conversation number
  - `1234` prefers the internal conversation ID
  - thread ID lookup remains available as a fallback
  - failed numeric lookups continue into normal text search
- Filters: mailbox, **multi-status (OR)** incl. Deleted, folder, assignee, sort
- Advanced filters (collapsible): date range, tags, customer, attachments, search-in scopes, waiting for reply
- **Manage → Settings → Smart Search:** default statuses, default mailbox, toggle “search message bodies”
- **Localization:** FreeScout JSON style (`Resources/lang/de.json`, `fr.json`); English source keys via `__()` / `__j()`
- Deleted conversations use `conversation.state` and display a red **Deleted** badge

### Custom Fields (optional module)

Shown only when the **Custom Fields** module is installed and active:

- Always-visible **Custom Fields** tile (same row as Mailbox/Status from 1200px width)
- Multiple field filters combined with **AND**; values within a field use **OR**
- **Type-aware operators and inputs:**
  - Single line text: is / contains / empty
  - Multi line: contains / empty
  - Number: equals / greater than / less than / between / empty
  - Date: on / before / after / between / empty
  - Dropdown: is (option checkboxes) / empty
  - Tags: contains any / empty
  - Multiselect dropdown: contains any (labels) / empty
- Result table: one column per selected custom field, **sortable** (`cf_{id}_asc` / `cf_{id}_desc`)
- Summary pills show field + operator + values (e.g. `Name enthält x`)

### Visual redesign

- Tile-based filter UI (mailbox, status, custom fields, assignee, folder, sort)
- Radio/checkbox panels instead of dense selects where helpful
- Animated “Show more options” section for advanced filters
- Floating filter panels (`position: fixed`, high z-index) so dropdowns are not clipped
- Compact status/CF pills on tiles
- Navbar inline search with portal suggestions (desktop expand / mobile constrained)
- Dark-theme-friendly styling

### Bulk actions

Select visible Smart Search results and:

- Assign or unassign conversations
- Update conversation status
- Add the same internal note to selected conversations

Bulk actions use POST + CSRF, re-check update policy per conversation, skip deleted/inaccessible items, and limit each request to a configurable maximum.

## Compatibility

- FreeScout 1.8.211 or newer recommended
- PHP 7.1+ syntax
- Laravel 5.5-compatible
- Bootstrap 3 + jQuery frontend
- MySQL, PostgreSQL, and SQLite-aware search queries
- Custom Fields module is optional

No FreeScout core files are modified.

## Installation

1. Extract the archive.
2. Upload the `SmartSearch` folder to:

   `Modules/SmartSearch`

3. If you previously used `AdamSmartSearchUI`, deactivate/remove that module first, then activate **Smart Search**.
4. Activate the module under **Manage → Modules**.
5. Rebuild and clear caches:

```bash
php artisan freescout:module-install smartsearch
php artisan freescout:build
php artisan freescout:module-build
php artisan view:clear
php artisan cache:clear
php artisan config:clear
```

## Configuration

Edit `Config/config.php` before deployment when needed:

- `show_topbar`
- `use_core_search_icon`
- `hide_core_search_icon`
- `min_query_len`
- `per_page`
- `search_thread_body`
- `bulk_max_selected`
- `bulk_note_max_length`
- `schema_cache_minutes`

Settings saved under Manage → Settings use the option keys `smartsearch.*`.
Previous `adamsmartsearchui.*` options are not migrated automatically — re-save defaults once after upgrading.

## Updates

FreeScout can update this module via the Modules page (`latestVersionUrl` / `latestVersionZipUrl` in `module.json`).
Release ZIPs must contain a top-level `SmartSearch/` folder.

## Security

See [SECURITY.md](SECURITY.md) for reporting instructions and implementation safeguards.
