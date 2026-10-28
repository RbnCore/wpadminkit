=== AdminKit — Admin Area Tools ===
Contributors: rbnbilisim
Tags: admin, back to top, sticky save, notices, admin color scheme, dashboard
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
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
* Adds nothing to the front end. An outside connection is made only when the RBN Kit → Overview page is opened, and only to GitHub (plugin catalog); if you turn off "Look up the plugin catalog on GitHub live" in the AdminKit settings, no network request is made at all.
* RBN Kit → Overview: **Installed** and **Plugin catalog** tabs. One-click install from the catalog: a signed GitHub release, with SHA-256 + Ed25519 verification required; after the install you still select "Activate" yourself.

== Installation ==

1. Upload the folder to `wp-content/plugins/` and activate it.
2. Turn modules on or off under RBN Kit → AdminKit, in the side menu just below Dashboard.

== Changelog ==

= 0.1.0 =
* First release: 5 modules (back to top, sticky save bar, notice organizer, hide side menu items, color theme), a module registry, tr_TR translation.
* Color theme (`color_theme`, off by default): the ready themes Night, Ocean, Forest, Sunset and Graphite, plus Custom colors; personal choice under Users → Admin colors, default for all users under RBN Kit → AdminKit. Live preview, Save, Cancel, Back to default; the contrast is checked with WCAG relative luminance (a warning + Fix below 4.5:1).
* Color match: the back-to-top button, sticky save bar, notice box and settings cards follow the chosen accent color (`--adminkit-accent`, `-contrast`, `-hover`, `-active`); the look does not change while the color theme is off.
* Menu: AdminKit sits in the shared **RBN Kit** menu just below Dashboard (Overview + AdminKit); it has no entry under Settings.
* Nothing is added to the front end. GitHub is contacted only when the plugin catalog page is opened (plain GET, 12-hour cache); this can be turned off in the settings. When `DISALLOW_FILE_MODS` is on, Install/Activate are hidden.
