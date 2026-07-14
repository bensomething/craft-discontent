# Content Tools

A Craft CMS plugin that adds practical filtering to the admin-facing Fields and Entry Types screens — a field-type dropdown and an “Unused only” toggle — so you can find and clean up unused fields and entry types quickly.

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later

## Installation

Install with Composer (this is a private plugin, so add the VCS repository first):

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:bensomething/craft-content-tools.git", "no-api": true }
]
```

```bash
composer require bensomething/craft-content-tools:dev-main
php craft plugin/install content-tools
```

## Usage

Open **Settings → Plugins → Content Tools** (the plugin’s gear). You get:

- **Fields** — the same list as Settings → Fields, with a field-type dropdown and an **Unused only** toggle (a field is “unused” when it isn’t attached to any field layout).
- **Entry Types** — _(coming next)_ a Section / Matrix Entry filter plus an **Unused only** toggle.
- **Settings** — a **Show in main navigation** switch (off by default) that adds a Content Tools item to the main CP nav.

The tool is admin-only and read-only; it works even when `allowAdminChanges` is disabled.
