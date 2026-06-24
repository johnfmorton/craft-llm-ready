# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Deleting a site no longer leaves orphaned section settings in project config. The plugin now prunes its `llm-ready.sectionSettings.*.{siteUid}` entries when a site is removed, so a later `project-config/apply` (or a fresh install of the config) no longer aborts referencing a site that no longer exists.
- The project config add/update/remove handlers and the install-time rebuild no longer abort with a `SiteNotFoundException` when a section setting references a missing site. `Sites::getSiteByUid()` throws rather than returning null for an unknown UID, which defeated the existing `=== null` guards; lookups now resolve to null and skip the orphaned entry as intended.

## [1.5.0] - 2026-06-12

### Added

- Expanded the built-in AI bot detection list with current crawlers: `Claude-SearchBot` and `Claude-User` (Anthropic), `Perplexity-User` (Perplexity), and `Meta-ExternalAgent`, `Meta-ExternalFetcher`, and `Meta-WebIndexer` (Meta) ([#16](https://github.com/johnfmorton/craft-llm-ready/issues/16))
- New `botUserAgents` config setting — a full replacement for the built-in bot user-agent list, for installs that want complete control. `additionalBotUserAgents` continues to append on top ([#16](https://github.com/johnfmorton/craft-llm-ready/issues/16))
- New `excludeBotUserAgents` config setting — remove specific entries from the effective list (e.g. drop a single default) without re-listing the whole list ([#16](https://github.com/johnfmorton/craft-llm-ready/issues/16))

### Changed

- Removed `Claude-Web` (legacy, not in Anthropic's current crawler docs) and `FacebookBot` (a general crawler, not AI-specific) from the default detection list. Add either back via `additionalBotUserAgents` if you still want it ([#16](https://github.com/johnfmorton/craft-llm-ready/issues/16))
- Removed `Google-Extended` and `Applebot-Extended` from the default list — these are robots.txt opt-out tokens, not request User-Agents, so they never appear in a `User-Agent` header and matching them was a no-op ([#16](https://github.com/johnfmorton/craft-llm-ready/issues/16))

### Documentation

- Brought the `config.php` template fully in sync with the available settings — it now includes `excludeSelector`, `autoInjectLinkHeader`, `titleField`, `authorOverride`, and the analytics options that were added in 1.4.0/1.4.1 but never documented in the template, and the `descriptionField` / `titleField` comments now describe the dot-notation, `()` method-call, Generated Field, and SEOmatic-resolver syntax
- Documented the HTTP `Link` discovery header (Auto-inject Link Header) and the analytics dashboard/purge permissions in `DOCUMENTATION.md`
- Overhauled `AI-INSTALL.md` to match 1.4.0/1.4.1/1.5.0: corrected the minimum Craft version to 5.9.18, added an SEO-plugin detection step (SEOmatic / Ether SEO / SEOmate / SEO Fields) for the Description/Title fields, an analytics setup step, an HTTP `Link` header verification test, and the Exclude Selector / Auto-inject Link Header / Title Field / Author Override settings

## [1.4.1] - 2026-06-09

### Fixed

- Auto-injected `Link` header is now emitted on `HEAD` requests as well as `GET`. Per RFC 9110, a `HEAD` response must carry the same headers as the equivalent `GET`, and some clients (uptime monitors, link checkers, `curl -I`) only issue `HEAD` ([#7](https://github.com/johnfmorton/craft-llm-ready/issues/7))

### Changed

- Bumped minimum Craft CMS to `^5.9.18` (was `^5.5.0`) so consumers no longer install Craft versions affected by [GHSA-gj2p-p9m4-c8gw](https://github.com/advisories/GHSA-gj2p-p9m4-c8gw), [GHSA-qrgm-p9w5-rrfw](https://github.com/advisories/GHSA-qrgm-p9w5-rrfw), and [GHSA-33m5-hqp9-97pw](https://github.com/advisories/GHSA-33m5-hqp9-97pw), all patched in Craft 5.9.18
- Stopped committing `composer.lock` — distributed plugins shouldn't ship lock files, since consumers resolve dependencies against their own. This also clears noise from Dependabot scans of transitive dependencies that don't actually affect consumers

## [1.4.0] - 2026-06-07

### Added

- New "Exclude Selector" setting under Content Extraction — strip decorative or non-content elements (e.g. carousels, `[data-nosnippet]`) from the HTML before Markdown conversion ([#3](https://github.com/johnfmorton/craft-llm-ready/issues/3))
- "Description Field" now supports dot notation for traversing nested fields and sub-objects (e.g. `seo.seoDescription` inside a ContentBlock field, or `seo.description` for an Ether SEO field), `()` method-call syntax (e.g. `metaData.getMetaDescription()` for SEO Fields), and Generated Field handles ([#4](https://github.com/johnfmorton/craft-llm-ready/issues/4))
- Native SEOmatic resolver: set Description Field to `seomatic:description` (or `seomatic:og-description`, `seomatic:twitter-description`) to use SEOmatic's full resolution chain — per-entry override → section default → global default, with Twig token parsing. No Generated Field required
- New "Title Field" front-matter setting — point at any field handle/path to override the front-matter `title:` value, with the same syntax as Description Field. Falls back to the entry's native title when unresolved ([#6](https://github.com/johnfmorton/craft-llm-ready/issues/6))
- New "Author Override" front-matter setting — write a single authoritative author name (e.g., an editorial team) to every entry's front matter instead of leaking individual editor names ([#6](https://github.com/johnfmorton/craft-llm-ready/issues/6))
- New CP dashboard widget — compact summary of last-30-day analytics for the current site (total requests, top bot, top page) with a click-through to the full dashboard. Hidden when analytics are disabled or the user lacks the `llm-ready:viewAnalytics` permission ([#5](https://github.com/johnfmorton/craft-llm-ready/issues/5))
- New "Auto-inject Link Header" setting (default on) — adds an HTTP `Link` response header (RFC 8288) pointing at the Markdown alternate, alongside the existing `<link rel="alternate">` HTML tag. Useful for crawlers that inspect headers without parsing HTML ([#7](https://github.com/johnfmorton/craft-llm-ready/issues/7))
- New `SEO-PLUGINS.md` documenting how to wire LLM Ready into SEOmatic, Ether SEO, SEOmate, and Studio Espresso SEO Fields
- New user permissions under "LLM Ready": "View the analytics dashboard" and the nested "Purge analytics data". Admins have both by default

### Changed

- When "Description Field" is explicitly configured but resolves to an empty value, the entry's description is now omitted rather than silently falling back to auto-extraction from other fields ([#4](https://github.com/johnfmorton/craft-llm-ready/issues/4))

### Security

- Analytics dashboard, JSON data endpoint, and purge action now require the corresponding permission. Previously any CP user could view analytics and trigger a purge. Existing non-admin users will lose access until granted the new permissions

## [1.3.2] - 2026-04-05

### Changed

- Chart legend filtering is now additive — clicking a bot or type shows only that item instead of hiding it; clicking it again shows all
- Clicking a chart legend item now updates the bot breakdown table, request types table, most accessed pages table, and stats cards to reflect the selected filter

## [1.3.1] - 2026-03-27

### Changed

- Chart legend items now show a pointer cursor on hover to indicate they are clickable for toggling datasets

### Added

- Documentation on how to block specific bots via `robots.txt`

## [1.3.0] - 2026-03-27

### Added

- Toggle on the Requests Over Time chart to view stacked breakdowns by bot or by request type

### Fixed

- Strip trailing slash from entry URLs before appending `.md`, preventing broken links like `/about/.md` in `llms.txt` and `<link rel="alternate">` discovery tags
- Homepage discovery `<link>` tag now points to `/llms.txt` instead of the non-existent `/.md`
- Homepage analytics requests are now logged with a meaningful path and displayed as "Homepage" in the analytics dashboard

## [1.2.2] - 2026-03-24

### Fixed

- "Last Seen" dates in the analytics bot breakdown now correctly display in the Craft system timezone instead of UTC

## [1.2.1] - 2026-03-24

### Added

- 301 redirect from `/.well-known/llms.txt` to `/llms.txt` so LLMs checking the RFC 8615 well-known path are directed to the canonical location
- Documentation for the analytics dashboard, including explanations of the four request types (entry, listing, llmstxt, negotiated) and data retention

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

[Unreleased]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.4.1...v1.5.0
[1.4.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.3.2...v1.4.0
[1.3.2]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.3.0...v1.3.1
[1.2.2]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/johnfmorton/craft-llm-ready/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/johnfmorton/craft-llm-ready/releases/tag/v1.0.0
