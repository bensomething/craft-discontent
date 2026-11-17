<img src="src/icon.svg" width="96" alt="Discontent">

# Discontent

Better **Sections**, **Entry Types** and **Fields** pages for Craft CMS: see where everything is used, find what isn't, and filter down to what you're cleaning up.

## Requirements

Craft CMS 5.10+ and PHP 8.2+. For Craft 6, use Discontent 2.x.

## Installation

Install from the **Plugin Store**, or with Composer:

```bash
composer require bensomething/craft-discontent:^1.0.0-beta
php craft plugin/install discontent
```

If Composer refuses, set your project's `minimum-stability` to `beta` (with `"prefer-stable": true`), or require the exact version.

## What it does

Discontent replaces Craft's Settings → Sections, Entry Types and Fields pages with its own. The new and edit screens stay Craft's, and everything is admin-only.

All three pages have search, sorting, and double-click to edit in a slideout. The search and filters live in the URL, so a filtered list can be bookmarked or shared.

### Sections

| Column | Shows |
|---|---|
| Entry Types | The entry types the section uses, by the name they have there |
| Sites | The sites it's enabled for, linked to their settings (multi-site only) |
| URLs | The URI format its entries get |
| Versioning | Whether its entries are versioned |

Filter by **section type**, or show: **Has / No URLs** and **Has / No versioning**.

### Entry Types

| Column | Shows |
|---|---|
| Used by | The sections and fields using it |
| Slug, Status | Whether authors see those fields |

Filter by **usage** (sections, Matrix or CKEditor), or show: **Unused**, **Has instances** (renamed where it's used, each instance listed under it), **Has / No slug** and **Has / No status**.

### Fields

| Column | Shows |
|---|---|
| Used by | The layouts using it |
| Used for keywords | Whether its values feed search |
| Translation Method | How its values are translated (multi-site only) |

Filter by **field type**, or show: **Unused**, **Has instances** (used under another label or handle, each instance listed under it), **Used / Not used for keywords** and **Translatable / Not translatable**.

A filter is only offered when it would actually narrow the list.

## Settings

Under **Settings → Plugins → Discontent**:

| Setting | Default | |
|---|---|---|
| Plugin name | Discontent | The name in the nav and page titles |
| Replace native pages | On | Off keeps Craft's own pages |
| Show in main navigation | Off | Adds a nav item with the three pages |
| Default view | Fields | The page the nav item opens |
