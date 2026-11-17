<img src="src/icon.svg" width="96" alt="Discontent">

# Discontent

A Craft CMS plugin that supercharges the admin-facing **Sections**, **Entry Types** and **Fields** screens with filtering — by type/usage, by whether they’re actually used, and by where they’re used under another name — so you can find and clean up fast.

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later

## Installation

Install Discontent from the **Plugin Store** in the control panel, or with Composer:

```bash
composer require bensomething/craft-discontent:^1.0.0-beta
php craft plugin/install discontent
```

If Composer refuses, your project's `minimum-stability` is `stable`. Set it to `beta` with `"prefer-stable": true`, or pin the exact version.

## What it does

By default Discontent **replaces** Craft’s native Settings → Sections, Entry Types and Fields index pages with enhanced versions (the New/Edit screens stay native). Everything is admin-only and read-through — it reuses Craft’s own edit, save, and delete actions.

**Sections**
- **Entry Types** column — the entry types each section uses, as it names them
- **Sites** column (multi-site installs) — the sites each section is enabled for, linked to their settings
- **URLs** and **Versioning** columns — the entry URI format entries get (or “No URLs”), and whether they’re versioned
- Filter by section type: **Singles**, **Channels**, or **Structures**
- View: **Show all**, **Has URLs** / **No URLs** (a section counts as routed if it has URLs in at least one site), or **Has versioning** / **No versioning**
- Search, sort, double-click a row to edit in a slideout, delete, and “New section”

**Entry Types**
- Filter by usage: **Section Entries**, **Matrix entries**, or **Nested CKEditor entries**
- View: **Show all**, **Unused** (used by no section or field), **Has instances** — entry types renamed where they’re used, each instance nested under it — **Has slug** / **No slug**, or **Has status** / **No status**
- **Slug** and **Status** columns — a tick when authors see those fields, a faint dash when they don’t
- Search, sort, double-click to edit in a slideout, delete, and “New entry type”

**Fields**
- Filter by field type (only types actually in use are listed)
- View: **Show all**, **Unused** (not in any field layout), **Has instances** — fields used under a different label or handle, each instance nested under its field — or **Used for keywords** / **Not used for keywords**
- **Used by** shows the layouts a field is in as chips, the first plus a “+N” for the rest, falling back to Craft’s count when not every layout can be named
- **Used for keywords** column — whether the field’s values feed element search
- **Translation Method** column (multi-site installs) — “Each site”, “Each site group”, “Each language” or “Custom”, with **Translatable** / **Not translatable** views
- Search, sort, double-click a row to edit in a slideout, delete, and “New field”

Each **Has** / **No** pair sits under its own heading in the view dropdown, and is only offered when it splits the list.

**Shareable views** — the current search/filter state lives in the URL, so you can bookmark or share a link to a filtered set.

## Settings

At **Settings → Plugins → Discontent**:

- **Plugin name** — the public-facing name (nav item, page titles, plugin list).
- **Replace native pages** — on by default; turn off to keep Craft’s native pages and leave the tool dormant.
- **Show in main navigation** — off by default; adds a top-level nav item with a Sections / Entry Types / Fields / Settings subnav.
- **Default view** — which page the main-nav item opens (shown when the nav item is enabled).

The pages live canonically at `/discontent/*`; with **Replace native pages** on, the native Settings links redirect there.
