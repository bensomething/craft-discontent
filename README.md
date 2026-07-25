# Discontent

A Craft CMS plugin that supercharges the admin-facing **Sections**, **Entry Types** and **Fields** screens with filtering — by type/usage, by whether they’re actually used, and by where they’re used under another name — so you can find and clean up fast.

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

By default Discontent **replaces** Craft’s native Settings → Sections, Entry Types and Fields index pages with enhanced versions (the New/Edit screens stay native). Everything is admin-only and read-through — it reuses Craft’s own edit, save, and delete actions.

**Sections**
- **URLs** and **Versioning** columns — the entry URI format entries get (or “No URLs”), and whether they’re versioned
- Filter by section type: **Singles**, **Channels**, or **Structures**
- View: **Show all**, **Has URLs**, **No URLs**, or **Has versioning** (a section counts as routed if it has URLs in at least one site)
- Search, sort, double-click a row to edit in a slideout, delete, and “New section”

**Entry Types**
- Filter by usage: **Section Entries**, **Matrix entries**, or **Nested CKEditor entries**
- View: **Show all**, **Unused** (used by no section or field), **Has instances** — entry types renamed where they’re used, each instance nested under it — **Has slugs**, or **Has statuses**
- **Show Slug** and **Show Status** columns — a tick when authors see those fields, a faint dash when they don’t
- Search, sort, double-click to edit in a slideout, delete, and “New entry type”

**Fields**
- Filter by field type (only types actually in use are listed)
- View: **Show all**, **Unused** (not in any field layout), **Has instances** — fields used under a different label or handle, each instance nested under its field — or **Used for keywords**
- **Used by** names the layout for fields used in only one, and counts the rest
- **Used for keywords** column — whether the field’s values feed element search
- Search, sort, double-click a row to edit in a slideout, delete, and “New field”

**Shareable views** — the current search/filter state lives in the URL, so you can bookmark or share a link to a filtered set.

## Settings

At **Settings → Plugins → Discontent**:

- **Plugin name** — the public-facing name (nav item, page titles, plugin list).
- **Replace native pages** — on by default; turn off to keep Craft’s native pages and leave the tool dormant.
- **Show in main navigation** — off by default; adds a top-level nav item with a Sections / Entry Types / Fields / Settings subnav.
- **Default view** — which page the main-nav item opens (shown when the nav item is enabled).

The pages live canonically at `/discontent/*`; with **Replace native pages** on, the native Settings links redirect there.
