# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Entry descriptions in `/llms.txt` and listing pages — each link now includes a brief description following the llms.txt spec format
- New "Description Field" setting to specify a field handle for entry descriptions (e.g., `summary`, `excerpt`)
- Auto-extract fallback that pulls a description from the first text field when no description field is configured
- Config file support — copy `src/config.php` to `config/llm-ready.php` to manage settings in code instead of the control panel

## [1.0.1] - 2026-03-21

### Fixed

- Homepage singles no longer appear in `/llms.txt` and listing pages with broken `/.md` URLs
- Sections with no listable entries (e.g. homepage singles) no longer show empty headings in `/llms.txt`

## [1.0.0] - 2026-03-21

### Added

- Markdown endpoint via `.md` URL suffix — append `.md` to any entry URL to get a Markdown version
- Content negotiation support — serve Markdown for requests with `Accept: text/markdown` header
- AI bot user-agent detection — automatically serve Markdown to known AI crawlers (GPTBot, ClaudeBot, Amazonbot, PerplexityBot, and others)
- Smart HTML-to-Markdown conversion using [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) with configurable CSS selectors for content extraction
- Dedicated LLM template support — assign a Twig template per section/site that outputs raw Markdown directly
- Auto-generated `/llms.txt` site index following the [llms.txt specification](https://llmstxt.org/)
- YAML front matter with entry metadata (title, date, author, canonical URL, section)
- Auto-injection of `<link rel="alternate" type="text/markdown">` discovery tags into HTML pages
- Listing page support — append `.md` to a section's base URL for a Markdown list of entries
- Per-section enable/disable control with optional LLM template configuration stored in project config for version control and multi-environment sync
- Markdown response caching via Craft's cache component with automatic invalidation on entry save/delete
- `X-Robots-Tag: noindex` header on Markdown responses to prevent search engine indexing (configurable)
- `Content-Type: text/markdown; charset=utf-8` header with explicit charset to prevent encoding issues
- `Link` canonical header pointing to the HTML version of the page
- Graceful fallback to field-level content extraction when template rendering fails
- Multi-site support with independent settings per section/site combination
- Permission checks on all Markdown endpoints — logged-in users without view permission receive a 403
- Template path traversal protection and XPath injection prevention

[Unreleased]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/johnfmorton/craft-llm-ready/releases/tag/v1.0.0
