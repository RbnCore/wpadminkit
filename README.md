# AdminKit

AdminKit adds small, independent tools to the WordPress admin area. Every tool is a module that can be switched on or off, and the plugin adds nothing to the public side of the site.

## Who it is for

WordPress site owners and editors who work in `wp-admin` every day: long settings pages, notices piling up, and no quick way back to the top of a page.

## Features

Each module can be turned on or off on its own. A module that is off loads no file and prints nothing.

- **Back to top.** A button appears on admin pages after you scroll past a threshold (400 px by default). Position, threshold and distance from the edge are adjustable. It has an accessible name and respects the reduced-motion preference.
- **Sticky save bar.** On long settings pages a fixed bar shows the page's own primary save button and clicks it, so form data and nonce are sent exactly as before. When the form changes it warns about unsaved changes and the browser asks before you leave. The bar hides while the page's own save button is visible.
- **Notice organizer.** Informational and warning notices are collected in one collapsible box. Error notices are never hidden and stay where they are; that part cannot be switched off.
- **Hide side menu items** (sample module, off by default). Hides selected menu items with CSS only. It does not change capabilities or access, and Settings and Plugins cannot be hidden.
- **Color theme** (off by default). Five ready themes (Night, Ocean, Forest, Sunset, Graphite) or your own colors. Each user picks a color under Users → Admin colors; the site default is set under RBN Kit → AdminKit. Live preview, and every color pair is checked against WCAG AA (4.5:1) with a one-click fix. No `localStorage`, no front-end output.

Defaults after activation: back to top, sticky save bar and notice organizer are on; color theme and the sample module are off.

## Requirements

- WordPress 6.4 or later
- PHP 8.0 or later
- No other plugin, library or build step

## Installation

1. Download `adminkit-0.1.0.zip` from [Releases](https://github.com/RbnCore/wpadminkit/releases). If you downloaded the package by hand, compare its SHA-256 with the `.sha256` asset next to it.
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, install and activate.
3. Open **RBN Kit → AdminKit** in the sidebar (just below Dashboard) and switch the modules you want. Save.

You can also copy the `adminkit` folder into `wp-content/plugins/`.

## Updating

Version 0.1.0 has no automatic update channel of its own.

- **RBN Kit → Overview** (Installed tab) shows an "Update available" label when GitHub has a newer release than the installed one.
- To update, upload the new `adminkit-<version>.zip` over the installed copy (Plugins → Add New → Upload Plugin → *Replace current*). Your settings live in the database and are not touched by a file update.
- When AdminKit is installed from the RBN Kit catalog instead, the package is checked before it is unpacked: SHA-256 and an Ed25519 signature, both taken from the GitHub release. A package that fails the check is not installed and leaves no files behind.

## Privacy and external requests

- Nothing is added to the front end: no script, style, query or output.
- The plugin contacts GitHub in one place only: opening **RBN Kit → Overview** asks `api.github.com/repos/<repo>/releases/latest` for each catalog entry that is marked as released (plain GET, 5-second timeout, 12-hour cache for a successful answer, 1-hour cache for an error, user agent `AdminKit/<version>`). No site address, user detail, cookie or token is sent.
- Turning off **"Look up the plugin catalog on GitHub live"** on the AdminKit settings page means no network request is made at all, and the Install buttons are disabled.
- Installing from the catalog only accepts packages from `https://github.com/RbnCore/<repo>/releases/download/<version>/`, requires the `.sha256` and `.sig` assets, and does not activate the plugin by itself. When `DISALLOW_FILE_MODS` is on, Install and Activate are hidden.
- On uninstall only the plugin's own data is removed: the `adminkit_settings` option and `adminkit_*` user meta.

## Translation

English is the source language. A Turkish (`tr_TR`) translation ships in `languages/`. The language you see follows your profile language (Users → Profile → Language), not only the site language. The shared **RBN Kit** menu is translated through its own text domain, `rbn-kit`.

## RBN Kit menu

All RBN plugins appear under one **RBN Kit** top-level menu just below Dashboard. AdminKit adds the "AdminKit" item; it has no entry under Settings. The menu order is fixed (Overview → AdminKit → Cookies and Consent → Content Health) and this plugin ships the shared `RbnKitMenu` file that the other plugins copy.

## Version and license

- Version 0.1.0
- License: GPL-2.0-or-later (see [LICENSE](LICENSE))
- Text domain: `adminkit`

## Development notes

- This repository is the plugin folder itself. The release package is a git archive of the repository root with the `adminkit/` prefix, so only files that belong in the package are committed here.
- Every module implements the `AdminKit\Module` interface (id, title, description, default state, settings schema, boot). Add your own with the `adminkit_modules` filter; the settings card, saving, sanitizing and defaults are generated from the schema.
- The plugin boots only when `is_admin()` is true; activation hooks are registered everywhere, including WP-CLI.
- Settings live in one option (`adminkit_settings`) written through the WordPress Settings API; locked fields are not stored.
- Tests, screenshots, documentation and publisher tools live in the project tree outside this repository, so they never reach the package.

## Links

- Repository: https://github.com/RbnCore/wpadminkit
- Releases: https://github.com/RbnCore/wpadminkit/releases
- Issues: https://github.com/RbnCore/wpadminkit/issues
- Author: https://github.com/RbnCore
