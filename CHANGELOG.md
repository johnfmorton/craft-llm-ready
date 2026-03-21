# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- Added `canView()` permission check on all Markdown endpoints — logged-in users without view permission now receive a 403 instead of entry content
- LLM template paths containing `..` or starting with `/` are now rejected to prevent directory traversal
- CSS selector values are now properly escaped in XPath expressions to prevent injection; unrecognized selectors are rejected instead of passed through

### Fixed

- Per-section settings (enabled toggle and LLM template path) were not saved to the database when clicking Save in the plugin settings page
- Data cache is now flushed when plugin settings are saved, so template and configuration changes take effect immediately without manual cache clearing

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
- Per-section enable/disable control with optional LLM template configuration
- Markdown response caching via Craft's cache component with automatic invalidation on entry save/delete
- `X-Robots-Tag: noindex` header on Markdown responses to prevent search engine indexing (configurable)
- `Content-Type: text/markdown; charset=utf-8` header with explicit charset to prevent encoding issues
- `Link` canonical header pointing to the HTML version of the page
- Graceful fallback to field-level content extraction when template rendering fails
- Multi-site support with independent settings per section/site combination

[Unreleased]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/johnfmorton/craft-llm-ready/releases/tag/v1.0.0
