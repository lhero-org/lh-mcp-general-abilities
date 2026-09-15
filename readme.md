# LH MCP General Abilities

General-purpose WordPress abilities for AI clients, covering the ordinary WordPress object layer: reading posts of any registered type, authoring drafts, searching users, and reading public site information.

Content authoring is draft-only by construction rather than by configuration. There is no status parameter anywhere in the plugin, so nothing it writes can become publicly visible without a human publishing it.

## Installation

1. Upload the `lh-mcp-general-abilities` folder to `/wp-content/plugins/`.
2. Network-activate via **Network Admin → Plugins** (multisite), or activate via **Plugins** (single site).
3. No configuration required — abilities are registered automatically.

Requires WordPress 6.9 or later, the release in which the Abilities API became a core API, and PHP 8.0 or later.

[LH MCP Block Transformer](https://lhero.org/portfolio/lh-mcp-block-transformer/) is an optional dependency, used to convert between Markdown, HTML and Gutenberg block markup. Without it, `get-post` still returns a post's raw content but cannot supply a Markdown rendering of it, and the two authoring abilities cannot accept content at all.

Exposing these abilities over MCP additionally requires the MCP Adapter plugin. The abilities are registered on the core Abilities API and work through it either way.

## Documentation

Full documentation — every ability, the capability model, and the reasoning behind the design — is at [https://lhero.org/portfolio/lh-mcp-general-abilities/](https://lhero.org/portfolio/lh-mcp-general-abilities/).

## Changelog

[https://lhero.org/portfolio/lh-mcp-general-abilities/changelog/](https://lhero.org/portfolio/lh-mcp-general-abilities/changelog/)
