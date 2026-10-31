=== AdminKit — Admin Area Tools ===
Contributors: rbnbilisim
Tags: admin, back to top, sticky save, notices, admin color scheme, dashboard
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Link: https://github.com/RbnCore

Adds features to the WordPress admin area, module by module. You turn each module on or off under RBN Kit → AdminKit.

== Description ==

* Back-to-top button: on every admin page; position, threshold and edge distance are adjustable.
* Sticky save bar: on long settings pages it triggers the existing save button and warns about unsaved changes.
* Notice organizer: collects informational notices in one box. Error notices are never hidden.
* Hide side menu items (example module, off by default): only changes the view, does not block access.
* Color theme (off by default): 5 ready themes or your own colors; per user, with a live preview; the contrast is checked against WCAG AA (4.5:1).
* Adds nothing to the front end. An outside connection is made only to GitHub: when the RBN Kit → Overview page is opened (plugin catalog) and inside WordPress' own update check (new versions of the RBN plugins; no token, cookie or site address is sent); if you turn off "Allow RBN plugins to check GitHub for updates and the plugin catalog" in the AdminKit settings, no network request is made at all.
* RBN Kit → Overview: **Installed** and **Plugin catalog** tabs. One-click install from the catalog: a signed GitHub release, with SHA-256 + Ed25519 verification required; after the install you still select "Activate" yourself.

== Installation ==

1. Upload the folder to `wp-content/plugins/` and activate it.
2. Turn modules on or off under RBN Kit → AdminKit, in the side menu just below Dashboard.

== Changelog ==

= 0.1.3 =
* Turning a module on or off now updates its panel immediately, no page reload: the Database maintenance and System Health panels (and any other panel that depends on a switch) appear or disappear in place, and their scripts start on the spot. If the panel cannot be refreshed, a notice offers a Reload link instead of leaving a stale panel behind.
* Setup hints no longer tell you to press Save: they now say the switch applies right away.
* Fixes: the color theme, Database maintenance and System Health hints all match the new behaviour, and the Turkish translation of the changed strings is updated.

= 0.1.2 =
* New: System Health module. A read-only report of the server, the database and WP-Cron on tabs of its own, with a button that copies the report for support.
* New: Database maintenance module. It shows what can be cleaned (revisions, expired transients, orphan meta), previews the count first, asks for an explicit confirmation and deletes in batches.
* New: Settings export and import. Download the settings of all RBN plugins as one JSON file, preview what will change, then apply it in one step; the write is atomic and a file that fails validation changes nothing.
* New: Instant switches. Where a screen offers them, a setting takes effect as soon as the switch is flipped (no Save button needed); a change that can lock you out asks for confirmation first.
* New: Toast notices module, and one shared toast and confirm component for every RBN plugin.
* One settings engine for every AdminKit module: schema-based fields, a live preview, per-section error messages and a single write path. Two simultaneous saves no longer overwrite each other.
* Central security layer: admin actions go through one capability, nonce and origin gate; outgoing host checks and rate limiting live in one place; database access goes through one query gate.
* One shared update card is used on the settings screen of all five plugins.
* The plugin catalog lists SecurityKit as well.
* The sticky save bar appears only on the visible tab and only while the form is dirty; inner tabs follow the browser back and forward buttons, and every screen has one page heading.
* The notice organizer is off by default and never hides error or action notices.
* Fixes: the privacy mask matches whole words only, a cache flush also removes rows of older cache versions, and the accent color no longer leaks into WordPress' own buttons and links.

= 0.1.1 =
* Central updater (core API level 3): the AdminKit core class Updater is the one update module for every RBN plugin (WPSeoKit, ContentHealth, CookieKit and AdminKit itself). Signed GitHub releases (SHA-256 + Ed25519), switched by the central Network setting; plugins register with Updater::register() instead of carrying their own updater.
* The plugin catalog lists all four plugins as released.

= 0.1.0 =
* First release: 5 modules (back to top, sticky save bar, notice organizer, hide side menu items, color theme), a module registry, tr_TR translation.
* Color theme (`color_theme`, off by default): the ready themes Night, Ocean, Forest, Sunset and Graphite, plus Custom colors; personal choice under Users → Admin colors, default for all users under RBN Kit → AdminKit. Live preview, Save, Cancel, Back to default; the contrast is checked with WCAG relative luminance (a warning + Fix below 4.5:1).
* Color match: the back-to-top button, sticky save bar, notice box and settings cards follow the chosen accent color (`--adminkit-accent`, `-contrast`, `-hover`, `-active`); the look does not change while the color theme is off.
* Menu: AdminKit sits in the shared **RBN Kit** menu just below Dashboard (Overview + AdminKit); it has no entry under Settings.
* Nothing is added to the front end. GitHub is contacted only when the plugin catalog page is opened (plain GET, 12-hour cache); this can be turned off in the settings. When `DISALLOW_FILE_MODS` is on, Install/Activate are hidden.
