# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.1] - 2026-03-24

### Added

- 301 redirect from `/.well-known/llms.txt` to `/llms.txt` so LLMs checking the RFC 8615 well-known path are directed to the canonical location
- Documentation for the analytics dashboard, including explanations of the four request types (entry, listing, llmstxt, negotiated) and data retention

### Fixed

- "Last Seen" dates in the analytics bot breakdown now display in the Craft system timezone instead of UTC

## [1.2.0] - 2026-03-24

### Added

- Opt-in analytics dashboard tracking AI bot visits to `.md` pages, `/llms.txt`, and content negotiation responses
- Bot breakdown table showing request counts and last-seen timestamps per crawler (GPTBot, ClaudeBot, PerplexityBot, etc.)
- Requests over time bar chart powered by Chart.js with date range filtering (7d / 30d / 90d / all time)
- Most accessed pages table with links to the served Markdown page and entry edit page
- Request type breakdown (entry, llmstxt, listing, negotiated)
- Multi-site support for analytics with site selector
- Configurable data retention period (default 90 days) with manual purge from dashboard
- Console command `llm-ready/analytics/purge` for cron-based data cleanup
- CP section for the analytics dashboard

### Changed

- Analytics dashboard edit links are only shown to users with permission to view the entry
- Use Yii's `registerLinkTag()` for discovery tag injection instead of manual HTML string replacement
- Use Yii's `getAcceptableContentTypes()` for content negotiation instead of manual Accept header parsing
- Use Yii's `Html::decode()` for HTML entity decoding instead of raw `html_entity_decode()`

### Fixed

- Logged-in users without section edit permissions no longer get a 403 error on public `.md` pages

## [1.1.1] - 2026-03-23

### Fixed

- Homepage singles no longer appear in the plugin settings page, since they can't serve `.md` URLs

## [1.1.0] - 2026-03-21

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

[Unreleased]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.2.1...HEAD
[1.2.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/johnfmorton/craft-llm-ready/releases/tag/v1.0.0
