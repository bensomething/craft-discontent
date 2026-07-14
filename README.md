# Discontent

A Craft CMS plugin that supercharges the admin-facing **Fields** and **Entry Types** screens with filtering — by type/usage and by whether they’re actually used — so you can find and clean up unused fields and entry types fast.

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later

## Installation

This is a private plugin, so add the VCS repository first:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:bensomething/craft-discontent.git", "no-api": true }
]
```

```bash
composer require bensomething/craft-discontent:dev-develop
php craft plugin/install discontent
```

## What it does

By default Discontent **replaces** Craft’s native Settings → Fields and Settings → Entry Types index pages with enhanced versions (the New/Edit screens stay native). Everything is admin-only and read-through — it reuses Craft’s own edit, save, and delete actions.

**Fields**
- Filter by field type (only types actually in use are listed)
- **Unused** toggle — fields not attached to any field layout
- Search, sort, double-click a row to edit in a slideout, delete, and “New field”

**Entry Types**
- Filter by usage: **Section Entries**, **Matrix entries**, or **Nested CKEditor entries**
- **Unused** toggle — entry types used by no section or field
- Search, sort, double-click to edit in a slideout, delete, and “New entry type”

**Shareable views** — the current search/filter state lives in the URL, so you can bookmark or share a link to a filtered set.

## Settings

At **Settings → Plugins → Discontent**:

- **Plugin name** — the public-facing name (nav item, page titles, plugin list).
- **Replace native pages** — on by default; turn off to keep Craft’s native pages and leave the tool dormant.
- **Show in main navigation** — off by default; adds a top-level nav item with a Fields / Entry Types / Settings subnav.
- **Default view** — which page the main-nav item opens (shown when the nav item is enabled).

The pages live canonically at `/discontent/*`; with **Replace native pages** on, the native Settings links redirect there.
