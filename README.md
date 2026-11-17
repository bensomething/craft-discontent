<img src="src/icon.svg" width="96" alt="Discontent">

# Discontent

Better **Sections**, **Entry Types** and **Fields** pages for Craft CMS: see where everything is used, find what isn't, and filter down to what you're cleaning up.

## Requirements

Craft CMS 6 and PHP 8.5+. For Craft 5, use Discontent 1.x.

## Installation

```bash
composer require bensomething/craft-discontent:^2.0.0-beta
php artisan craft:plugin:install discontent
```

## What it does

Discontent replaces Craft's Settings → Sections, Entry Types and Fields pages with its own. The new and edit screens stay Craft's, and everything is admin-only.

All three pages have search, sorting, and double-click to edit in a slideout. The **View** menu shows, hides and reorders columns. The search and filters live in the URL, so a filtered list can be bookmarked or shared.

### Sections

| Column | Shows |
|---|---|
| Entry Types | The entry types the section uses, by the name they have there |
| Sites | The sites it's enabled for, linked to their settings (multi-site only) |
| URLs | The URI format its entries get |
| Versioning | Whether its entries are versioned |
| Workflow | The workflow its drafts go through (Craft Pro, once a workflow exists) |

Filter by **section type**, or show: **Has / No URLs**, **Has / No versioning** and **Has / No workflow**.

### Entry Types

| Column | Shows |
|---|---|
| Used by | The sections and fields using it |
| Slug, Status, Post Date, Expiry Date | Whether authors see those fields |

Filter by **usage** (sections, Matrix, CKEditor or other nested entries), or show: **Unused**, **Has instances** (renamed where it's used, each instance listed under it), and **Has / No** for each of the four fields.

### Fields

| Column | Shows |
|---|---|
| Used by | The layouts using it |
| Used for keywords | Whether its values feed search |
| Translation Method | How its values are translated (multi-site only) |

Filter by **field type**, or show: **Unused**, **Has instances** (used under another label or handle, each instance listed under it), **Used / Not used for keywords** and **Translatable / Not translatable**.

A filter is only offered when it would actually narrow the list.

## Navigation

Discontent adds a nav item for admins at the end of the **Content** group, with the three pages under it. Rename, move or hide it with Craft's own **Settings → Control Panel Navigation**.

## Settings

Under **Settings → Plugins → Discontent**:

| Setting | Default | |
|---|---|---|
| Replace native pages | On | Off keeps Craft's own pages |
| Default view | Fields | The page the nav item opens |

Either can be set in `config/craft/discontent.php` instead (publish it with `php artisan vendor:publish --tag=discontent`), which locks it in the control panel.
