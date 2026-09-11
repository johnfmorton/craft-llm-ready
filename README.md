# LLM Ready for Craft CMS

_LLM Ready_ makes your Craft CMS site machine-readable by serving clean Markdown versions of your content to AI crawlers and LLMs. Append `.md` to any entry URL, and LLM Ready converts the page to Markdown with YAML front matter — no extra templates required.

> **⚠️ Work in progress — you are on the `webmcp` branch.** This branch adds opt-in support for [WebMCP](https://github.com/webmachinelearning/webmcp), an emerging web standard that lets a page offer read-only tools to AI agents running in the visitor's browser. WebMCP itself is still in development: it is a Chrome/Edge origin trial working its way through the standards process, and its API is still changing. Nothing on this branch is released. It is being tested against real browsers and agents before it ships in a stable version. Install it only if you want to try WebMCP support and report what you find — see [Installing this branch](#installing-this-branch). For the stable plugin, use the [`main` branch](https://github.com/johnfmorton/craft-llm-ready) or the Plugin Store.

For complete documentation, see the [LLM Ready Documentation](DOCUMENTATION.md) in this repo.

**Using an AI coding assistant?** Point your agent to [AI-INSTALL.md](AI-INSTALL.md) for automated installation and configuration.

## Highlights

* **Zero configuration required** — works out of the box with no template changes. Install the plugin and your content is immediately available as Markdown.
* **Append `.md` to any entry URL** to get a clean Markdown version using your existing URLs — no separate URL prefix needed.
* **Crawlers find your Markdown on their own** — `/llms.txt` lists every entry's `.md` URL, and each HTML page advertises its Markdown alternate through a `<link rel="alternate">` tag and an HTTP `Link` header.
* **Three detection methods**: `.md` URL suffix, `Accept: text/markdown` content negotiation, and AI bot user-agent detection. The first two are on by default; user-agent detection serves Markdown on the *canonical* URL and is **off by default**, because a response that varies by `User-Agent` can be cached and replayed to real visitors by a shared cache. Safe to enable on sites served straight from their origin — see [Why User-Agent detection is off by default](DOCUMENTATION.md#why-user-agent-detection-is-off-by-default).
* **Cache and CDN safe** — Markdown served on a canonical URL is never storable, page caches like Blitz are opted out through their own API, and `.md` URLs and `/llms.txt` each keep a single representation, so everything a crawler fetches stays fully cacheable behind Cloudflare, Fastly, or Varnish.
* **Tells you whether a shared cache is in front of your site** — the settings page reads the headers a proxying edge stamps on requests (Cloudflare in proxied mode, Fastly, Varnish, Akamai, Azure Front Door), checks Blitz's configuration, and offers a probe that requests any URL twice and inspects the cache headers, so the User-Agent detection decision is made on evidence. Results are kept per environment, so a local checkout can show what production found.
* **Respects `noindex`** — entries marked `noindex` in SEOmatic or Ether SEO are excluded from `/llms.txt`, listing pages, and all Markdown output, the same way a search engine would treat them.
* **Smart HTML-to-Markdown conversion** extracts main content and strips navigation, footers, scripts, and other non-content elements — no template tags to add.
* Optionally assign dedicated Twig templates per section that output raw Markdown for full control.
* **Real-time rendering** — Markdown is generated on demand and cached, so content is always up to date without queue jobs or batch generation.
* Auto-generates a `/llms.txt` site index following the [llms.txt specification](https://llmstxt.org/) — and it can be switched off independently if you only want the `.md` URLs. Its title and site description can be plain text in the settings or sourced from a field on a Single or global set so content editors own them.
* **Listing page support** — append `.md` to a section's base URL to get a Markdown index of entries.
* **Built-in analytics** — an opt-in dashboard shows which AI bots visit your site, what they read, and how often, with per-site breakdowns, a CP widget, and configurable data retention.
* **WebMCP tools (opt-in)** — register read-only tools (`get-page-content`, `get-site-overview`) for AI agents running in your visitor's browser, via the emerging [WebMCP standard](https://github.com/webmachinelearning/webmcp) (origin trial in Chrome 149+/Edge 150+). Same content and visibility rules as the `.md` URLs; a silent no-op in browsers without the API. See [WebMCP tools](DOCUMENTATION.md#webmcp-tools).
* YAML front matter with entry metadata (title, date, all authors, canonical URL, section) — with settings to source the title, description, and author from any field or a Twig expression (show authors in some sections only, combine several author fields), including SEOmatic's resolved meta description, plus an event for adding keys of your own.
* **Project config support** — per-section settings are stored in Craft's project config for version control and multi-environment sync, and any setting overridden in `config/llm-ready.php` is flagged on the settings page.
* Per-section enable/disable control from the plugin settings page.
* Caches Markdown output with automatic invalidation when entries are saved.
* `X-Robots-Tag: noindex` header prevents search engines from indexing Markdown responses.

## Requirements

This plugin requires Craft CMS 5.9.18 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

**Using an AI coding assistant?** See [AI-INSTALL.md](AI-INSTALL.md) for a step-by-step guide your agent can follow to install, verify, and configure the plugin automatically.

### From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "LLM Ready". Then press "Install".

### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require johnfmorton/craft-llm-ready

# tell Craft to install the plugin
./craft plugin/install llm-ready
```

### Installing this branch

The `webmcp` branch is not in the Plugin Store and has no release tag. Composer can install it directly, because Packagist tracks every branch of this repository as a `dev-` version:

```bash
# go to the project directory
cd /path/to/my-project.test

# switch to (or install) the WebMCP work-in-progress branch
composer require "johnfmorton/craft-llm-ready:dev-webmcp"

# first-time install only — a site that already runs LLM Ready just needs the Composer step
./craft plugin/install llm-ready
```

A standard Craft project accepts the `dev-webmcp` constraint as is: its `composer.json` ships with `"minimum-stability": "dev"` and `"prefer-stable": true`, so the explicit dev constraint is honored while everything else stays on stable releases. Composer keeps following the branch — run `composer update johnfmorton/craft-llm-ready` to pick up new commits.

Then turn on **Enable WebMCP Tools** in the plugin settings and follow [Testing locally](DOCUMENTATION.md#testing-locally) in the documentation, which covers the browser flag and Google's WebMCP inspector extension.

To go back to the stable plugin:

```bash
composer require "johnfmorton/craft-llm-ready:^1.9"
```

Found something? Please [open an issue](https://github.com/johnfmorton/craft-llm-ready/issues) and mention that you are on the `webmcp` branch.
