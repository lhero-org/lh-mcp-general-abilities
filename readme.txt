=== LH MCP General Abilities ===
Contributors: shawfactor
Donate link: https://lhero.org/portfolio/lh-mcp-general-abilities/
Tags: mcp, ai, abilities, content, users
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.9.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

General-purpose WordPress abilities for AI clients: read posts, author drafts, search users, and read public site information.

== Description ==

Registers nine general-purpose abilities on the WordPress Abilities API, covering the ordinary WordPress object layer: posts of any registered type, the post types themselves, users, and basic site information.

The two authoring abilities are draft-only by construction rather than by configuration. There is no status parameter in either input schema, nothing reads one if passed, and the status is written as a literal, so no call through this plugin can publish, schedule, or otherwise make content live. Which post types may be written is an inclusion allowlist, filterable, and defaults to posts and pages only.

Full documentation, including every ability and the reasoning behind the design, lives at [the plugin's documentation page](https://lhero.org/portfolio/lh-mcp-general-abilities/).

= Abilities =

* get-post, list-posts, list-post-types — reading content
* create-post, update-post — authoring drafts
* search-users, get-site-info — users and site
* list-mcp-resources, read-mcp-resource — MCP resource discovery

= Related plugins =

[LH MCP Block Transformer](https://lhero.org/portfolio/lh-mcp-block-transformer/) is an optional dependency. It converts between Markdown, HTML and Gutenberg block markup. Without it, get-post still returns the post's raw content but cannot supply a Markdown rendering of it, and the two authoring abilities cannot accept content at all.

Exposing these abilities over MCP additionally requires the MCP Adapter plugin. The abilities themselves are registered on the core Abilities API and work through it regardless.

== Installation ==

1. Upload the `lh-mcp-general-abilities` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

No configuration is required. Abilities become available to any caller whose authenticated account holds the required capability.

== Frequently Asked Questions ==

= Can this publish content? =

No. There is no status parameter anywhere in the plugin, and update-post refuses any post that is not currently a draft, pending, or auto-draft. Publishing stays a human action.

= What if something does not work? =

LH MCP General Abilities, like all [LocalHero](https://lhero.org) plugins, is built to WordPress standards. If something misbehaves, first deactivate all other plugins and switch to a core theme to rule out a conflict.

If the problem persists, please leave a post in the support forum: [https://wordpress.org/support/plugin/lh-mcp-general-abilities/](https://wordpress.org/support/plugin/lh-mcp-general-abilities/).

= What if I need a feature that is not in the plugin? =

Please get in touch about custom work here: [https://shawfactor.com/contact/](https://shawfactor.com/contact/)

== Changelog ==

Only the most recent releases are listed here. The complete changelog is at [https://lhero.org/portfolio/lh-mcp-general-abilities/changelog/](https://lhero.org/portfolio/lh-mcp-general-abilities/changelog/).

= 1.9.6 =
* Added a .gitignore so runtime files such as error_log are never committed to the repository. No functional change.

= 1.9.5 =
* Normalised the main plugin file's indentation, which had been one level too deep since the removal of a top-level wrapper in 1.6.1. Whitespace only.

= 1.9.4 =
* Rewrote readme.md, which had drifted out of date: it documented list-posts parameters that no longer exist and omitted the two authoring abilities. Reference documentation now lives on the plugin's documentation page.
