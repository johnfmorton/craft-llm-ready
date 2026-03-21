# LLM Ready for Craft CMS

_LLM Ready_ makes your Craft CMS site machine-readable by serving clean Markdown versions of your content to AI crawlers and LLMs. Append `.md` to any entry URL, and LLM Ready converts the page to Markdown with YAML front matter — no extra templates required.

For complete documentation, see the [LLM Ready Documentation](DOCUMENTATION.md) in this repo.

## Highlights

* Append `.md` to any entry URL to get a clean Markdown version.
* Three detection methods: `.md` URL suffix, `Accept: text/markdown` content negotiation, and AI bot user-agent detection.
* Smart HTML-to-Markdown conversion extracts main content and strips navigation, footers, scripts, and other non-content elements.
* Optionally assign dedicated Twig templates per section that output raw Markdown for full control.
* Auto-generates a `/llms.txt` site index following the [llms.txt specification](https://llmstxt.org/).
* YAML front matter with entry metadata (title, date, author, canonical URL, section).
* Auto-injects `<link rel="alternate" type="text/markdown">` discovery tags into HTML pages.
* Per-section enable/disable control from the plugin settings page.
* Caches Markdown output with automatic invalidation when entries are saved.
* Listing page support — append `.md` to a section's base URL to get a Markdown list of entries.
* `X-Robots-Tag: noindex` header prevents search engines from indexing Markdown responses.

## Requirements

This plugin requires Craft CMS 5.5.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

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
# craft-llm-ready
