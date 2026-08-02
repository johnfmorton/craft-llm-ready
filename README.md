# LLM Ready for Craft CMS

_LLM Ready_ makes your Craft CMS site machine-readable by serving clean Markdown versions of your content to AI crawlers and LLMs. Append `.md` to any entry URL, and LLM Ready converts the page to Markdown with YAML front matter — no extra templates required.

For complete documentation, see the [LLM Ready Documentation](DOCUMENTATION.md) in this repo.

**Using an AI coding assistant?** Point your agent to [AI-INSTALL.md](AI-INSTALL.md) for automated installation and configuration.

## Highlights

* **Zero configuration required** — works out of the box with no template changes. Install the plugin and your content is immediately available as Markdown.
* **Append `.md` to any entry URL** to get a clean Markdown version using your existing URLs — no separate URL prefix needed.
* **Crawlers find your Markdown on their own** — `/llms.txt` lists every entry's `.md` URL, and each HTML page advertises its Markdown alternate through a `<link rel="alternate">` tag and an HTTP `Link` header.
* **Three detection methods**: `.md` URL suffix, `Accept: text/markdown` content negotiation, and AI bot user-agent detection. The first two are on by default; user-agent detection serves Markdown on the *canonical* URL and is **off by default**, because a response that varies by `User-Agent` can be cached and replayed to real visitors by a shared cache. Safe to enable on sites served straight from their origin — see [Why User-Agent detection is off by default](DOCUMENTATION.md#why-user-agent-detection-is-off-by-default).
* **Cache and CDN safe** — Markdown served on a canonical URL is never storable, page caches like Blitz are opted out through their own API, and `.md` URLs and `/llms.txt` each keep a single representation, so everything a crawler fetches stays fully cacheable behind Cloudflare, Fastly, or Varnish.
* **Respects `noindex`** — entries marked `noindex` in SEOmatic or Ether SEO are excluded from `/llms.txt`, listing pages, and all Markdown output, the same way a search engine would treat them.
* **Smart HTML-to-Markdown conversion** extracts main content and strips navigation, footers, scripts, and other non-content elements — no template tags to add.
* Optionally assign dedicated Twig templates per section that output raw Markdown for full control.
* **Real-time rendering** — Markdown is generated on demand and cached, so content is always up to date without queue jobs or batch generation.
* Auto-generates a `/llms.txt` site index following the [llms.txt specification](https://llmstxt.org/) — and it can be switched off independently if you only want the `.md` URLs.
* **Listing page support** — append `.md` to a section's base URL to get a Markdown index of entries.
* **Built-in analytics** — an opt-in dashboard shows which AI bots visit your site, what they read, and how often, with per-site breakdowns, a CP widget, and configurable data retention.
* YAML front matter with entry metadata (title, date, author, canonical URL, section) — with settings to source the title, description, and author from any field, including SEOmatic's resolved meta description.
* **Project config support** — per-section settings are stored in Craft's project config for version control and multi-environment sync.
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
