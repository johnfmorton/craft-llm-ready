# LLM Ready Documentation

LLM Ready serves Markdown versions of your Craft CMS pages to AI crawlers and large language models. It works out of the box with zero template changes — install the plugin and your content is immediately available as Markdown.

> **Using an AI coding assistant?** Point your agent to [AI-INSTALL.md](AI-INSTALL.md) for a step-by-step installation and configuration runbook designed for Claude Code, Codex, Gemini, and similar tools.

## How it works

LLM Ready intercepts requests for Markdown content using three detection methods:

1. **`.md` URL suffix** — Append `.md` to any entry URL (e.g., `/blog/my-post.md`). This is the primary method used by AI crawlers in practice, and the one to rely on.
2. **Content negotiation** — Requests with an `Accept: text/markdown` header receive Markdown instead of HTML.
3. **AI bot user-agent detection** — Known AI crawlers (GPTBot, ClaudeBot, etc.) receive Markdown on the canonical URL. **Off by default** — see below.

When a Markdown request is detected, LLM Ready resolves the entry from the URL, converts its content to Markdown, and serves it with appropriate headers.

Crawlers find the `.md` URLs on their own: `/llms.txt` lists every one of them, and each HTML page carries a `<link rel="alternate">` tag and an HTTP `Link` header pointing at its Markdown alternate. Every one of those is a distinct URL whose response never varies, so the whole thing stays cacheable.

### Why User-Agent detection is off by default

Methods 2 and 3 change the response *on the canonical URL* based on a request header. Method 3 is the risky one: a shared cache that stores the page under the URL alone cannot tell the two representations apart, so a single AI-bot request can be cached and then replayed to every subsequent visitor as raw Markdown.

The standards answer is to declare `Vary: User-Agent`. That is correct but unusable — `User-Agent` has effectively unbounded cardinality, so honouring it would give every browser build its own cache entry and destroy the hit ratio. That is precisely why Cloudflare, among others, ignores `Vary` for HTML. Correct and cache-efficient are mutually exclusive here, so the setting defaults to off and the canonical URL keeps a single representation.

**Turn it on if nothing caches in front of your site.** Served straight from the origin, it works exactly as before with no downside. Behind Cloudflare, Fastly, Varnish, or a platform edge such as Servd, leave it off and let `.md` plus discovery do the work.

Markdown served on the canonical URL — by either method — carries `Cache-Control: private, no-store` and `Vary: User-Agent, Accept` so that it is never stored by a shared cache. The `.md` URLs are unaffected and remain fully cacheable.

> **Upgrading from 1.5.x or earlier?** This setting used to default to on. An upgrade migration pins it to on for your site, so the upgrade itself changes nothing — the migration won't switch a working feature off behind your back. Fresh installs get the new default of off.
>
> **You should still make the change yourself if anything caches in front of your site.** Turn AI Bot User-Agent Detection off in the plugin settings. That removes the variation on the canonical URL rather than only neutralising it with cache headers, and your crawlers keep working through `.md`, `/llms.txt`, and the discovery tag and header. Origin-only sites can leave it on.

## Quick start

After installation, test it immediately:

```bash
# Append .md to any entry URL
curl https://your-site.test/blog/my-post.md

# Or use content negotiation
curl -H "Accept: text/markdown" https://your-site.test/blog/my-post

# View the auto-generated site index
curl https://your-site.test/llms.txt
```

## Preview targets

You can preview the Markdown version of an entry directly from the Craft control panel by adding an **LLM version** preview target to your sections.

1. Go to **Settings > Sections** and edit the section you want to configure.
2. In the **Preview Targets** table, click **+ Add a row**.
3. Set the **Label** to `LLM version` (or any name you prefer).
4. Set the **URL Format** to `{url}.md`.
5. Enable **Auto-refresh** if you want the preview to update as you edit.

This gives content authors a side-by-side view of the HTML page and its Markdown equivalent, making it easy to verify how the content will appear to AI crawlers and LLMs.

## Markdown conversion

LLM Ready converts your HTML pages to Markdown in two ways:

### Default: automatic HTML-to-Markdown conversion

When no dedicated LLM template is configured for a section, LLM Ready renders the entry's normal Twig template, then:

1. Extracts the main content area using configurable CSS selectors (defaults to `main, article, [role="main"], .content, #content`).
2. Strips non-content elements: `<script>`, `<style>`, `<nav>`, `<footer>`, `<header>`, `<audio>`, `<video>`, `<iframe>`, `<form>`, `<svg>`.
3. Converts the remaining HTML to Markdown using the [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) library.
4. Prepends YAML front matter with entry metadata.

If the template fails to render (e.g., a missing template or a Twig error), LLM Ready falls back to extracting content directly from common entry field handles (`body`, `content`, `text`, `description`, `summary`).

### Dedicated LLM templates

For full control over the Markdown output, you can assign a dedicated Twig template to any section. This template outputs raw Markdown directly — no HTML conversion is performed.

Configure LLM templates in the plugin settings page under **Section Settings**. Enter the template path relative to your `templates/` directory (e.g., `_llm/blog`).

The template receives the `entry` variable, just like a normal entry template:

```twig
# {{ entry.title }}

{{ entry.postDate|date('F j, Y') }}

{{ entry.body }}

{% if entry.relatedEntries|length %}
## Related

{% for related in entry.relatedEntries.all() %}
- [{{ related.title }}]({{ related.url }})
{% endfor %}
{% endif %}
```

### Working with CKEditor fields

CKEditor fields in Craft CMS 5 store content as a collection of typed chunks. When building dedicated LLM templates, you need to handle these chunks correctly to preserve formatting.

**Markup chunks** (`craft\ckeditor\data\Markup`) contain HTML content. To convert this HTML into properly formatted Markdown, use the `getMarkdown()` method:

```twig
{% for chunk in entry.myCkeditorField %}
  {% if chunk.type == 'markup' %}
    {{ chunk.getMarkdown()|raw }}
  {% elseif chunk.type == 'entry' %}
    {# Handle nested entry blocks (images, videos, etc.) #}
    {% set block = craft.app.entries.getEntryById(chunk.entryId) %}
    {# ... render block based on type ... #}
  {% endif %}
{% endfor %}
```

**Do not use** `{{ chunk }}`, `{{ chunk|striptags }}`, or `{{ chunk|raw }}` for markup chunks. These all produce plain text with no paragraph breaks, headings, links, or other formatting — resulting in a single wall of unreadable text.

The `getMarkdown()` method is available since CKEditor plugin version 4.8.0. It converts the chunk's HTML into Markdown using `league/html-to-markdown`, preserving:

- Paragraph breaks
- Headings
- Links
- Lists (ordered and unordered)
- Images (with alt text)
- Bold, italic, and other inline formatting
- Code blocks

You can optionally pass an array of `HtmlConverter` configuration options: `{{ chunk.getMarkdown({hard_break: true})|raw }}`.

## YAML front matter

All Markdown responses include YAML front matter with entry metadata:

```yaml
---
title: "My Blog Post"
date: 2026-03-21T10:00:00-07:00
author: "John Morton"
canonical_url: "https://example.com/blog/my-post"
section: "Blog"
---
```

On a Craft 5 entry with more than one author, `author:` lists all of them: `author: "John Morton, Jane Doe"`.

### Customizing the title and author

Two settings under **Front Matter** control the `title:` and `author:` values. Each accepts a plain value or a Craft object template — the same `{{ ... }}` syntax as an entry type's Title Format or a section's URI format.

**Title Field** — blank uses the entry's native title. A field handle or path (`longTitle`, `seo.title`, `metaData.getTitle()`, `seomatic:title`) resolves that field, with the same syntax as Description Field. A value containing `{` is rendered as an object template. Either way, an empty result falls back to the entry's native title, so every response has one.

```twig
{# Title: prefer a longer marketing title when it's filled in #}
{{ entry.longTitle ?: entry.title }}
```

**Author Override** — blank uses each entry's own authors: every author set on the entry, comma-separated, in the order they appear in the control panel (`Jane Doe, Bob Smith`). A fixed name (`Acme Editorial Team`) is written to every entry. A value containing `{` is rendered as an object template per entry; when it renders to nothing, the `author:` line is omitted.

Inside a template the entry is available as `entry` (and as Craft's usual `object`). Some examples:

```twig
{# Show authors only in the blog and news sections. Inside those sections this
   mirrors the default: every author, comma-separated, falling back to the
   username for users who haven't entered a name #}
{% if entry.section.handle in ['blog', 'news'] %}{{ entry.authors|map(a => a.fullName ?: a.username)|join(', ') }}{% endif %}

{# A plain-text "external authors" field, falling back to related author entries #}
{{ entry.externalAuthors ?: entry.entryAuthors.all()|map(a => a.title)|join(', ') }}

{# Omit the author line on every entry #}
{{ '' }}
```

The result is reduced to plain text (tags stripped, entities decoded, whitespace collapsed) and escaped for YAML by the plugin, so the template outputs only the value — not the `author:` key, and no quoting. A template that throws logs a warning and is treated as empty, so a typo in a setting can't break every Markdown response. Object templates come from plugin settings, which need an admin (or `config/llm-ready.php`) to change — the same trust boundary as Craft's own title and URI formats.

### Extending the front matter from a module

For anything beyond a single value — extra keys, a YAML list of authors, per-section rules written in PHP — listen for `MarkdownService::EVENT_DEFINE_FRONT_MATTER`. The event carries the entry, the site and the plugin's resolved keys in output order; handlers can add, change or remove any of them.

```php
use johnfmorton\llmready\events\DefineFrontMatterEvent;
use johnfmorton\llmready\services\MarkdownService;
use yii\base\Event;

Event::on(
    MarkdownService::class,
    MarkdownService::EVENT_DEFINE_FRONT_MATTER,
    function(DefineFrontMatterEvent $event) {
        $entry = $event->entry;

        // Replace the scalar author with a YAML list of related author entries
        $authors = $entry->entryAuthors->collect()->pluck('title')->all();
        if ($authors) {
            unset($event->frontMatter['author']);
            $event->frontMatter['authors'] = $authors;
        }

        // Add a key of your own
        $event->frontMatter['reading_time'] = $entry->readingTime . ' min';
    }
);
```

A string value becomes a scalar, a list of strings becomes a sequence, and `null` or an empty value drops the key. Values are escaped by the plugin, so pass plain text. Keys must match `[A-Za-z_][A-Za-z0-9_-]*`; anything else is skipped with a warning. The output is cached for **Cache TTL** seconds like the rest of the Markdown, so a change to something a handler reads from outside the entry itself (a related author's title, say) shows up when the cache expires or the entry is resaved.

## /llms.txt

LLM Ready auto-generates a `/llms.txt` file following the [llms.txt specification](https://llmstxt.org/). This file serves as a site index for LLMs, listing all enabled sections with links to each entry's Markdown version.

The generated file includes:

- **H1**: Your site name
- **Blockquote**: An optional site description (configured in plugin settings, or handed to editors through a global set field — see [Letting editors manage the site description](#letting-editors-manage-the-site-description))
- **H2 sections**: One per enabled Craft section, with a list of entry links

Example output:

```markdown
# My Website

> A blog about web development and Craft CMS.

## Blog

- [My First Post](https://example.com/blog/my-first-post.md)
- [Another Post](https://example.com/blog/another-post.md)

## News

- [Big Announcement](https://example.com/news/big-announcement.md)
```

### Letting editors manage the site description

The **Site Description** setting is a plugin setting, so it is stored in project config and can only be changed by an admin, and not at all in production when `allowAdminChanges` is off. If content editors should own that text, point the setting at content instead: a value containing `{` is rendered as a Craft object template, the same `{{ ... }}` syntax **Title Field** and **Author Override** accept, with the site available as `site` (and as Craft's usual `object`).

The natural source is a global set. Add a Plain Text or rich-text field such as `llmDescription` to a global set with the handle `siteInfo`, give editors permission to edit that global set, then set Site Description to:

```twig
{{ siteInfo.llmDescription }}
```

Global sets are available by handle exactly as in a site template, and the value editors enter is content, not project config, so it deploys with the database and can differ per site. The same works for a field on a Single:

```twig
{{ craft.entries.section('home').site(site).one().summary ?? '' }}
```

Rich-text output is reduced to plain text: tags are stripped, entities decoded, and each paragraph or line break becomes its own line of the blockquote, so a two-paragraph description stays two paragraphs. A template that renders to nothing omits the blockquote; one that throws logs a warning and omits it too, so a typo in the setting can't take down `/llms.txt`. The cached file is dropped whenever a global set or entry is saved, so an editor's change is live on the next request. Object templates come from plugin settings, which need an admin (or `config/llm-ready.php`) to change — the same trust boundary as Craft's own title and URI formats.

## Listing pages

Append `.md` to a section's base URL to get a Markdown list of all entries in that section:

```bash
curl https://your-site.test/blog.md
```

This returns a Markdown document with the section name as a heading and a bulleted list of entry links pointing to their `.md` URLs.

## Discovery

LLM Ready auto-injects a `<link>` tag into the `<head>` of your HTML pages so AI crawlers can discover the Markdown version:

```html
<link rel="alternate" type="text/markdown" href="https://example.com/blog/my-post.md">
```

This tag is added automatically to all pages in enabled sections. You can disable it in the plugin settings.

**Note:** Your page templates must include a `<head>` element for the discovery tag to be rendered. Craft automatically injects registered head tags before the closing `</head>` tag.

### HTTP `Link` header

In addition to (or instead of) the HTML tag, LLM Ready can advertise the Markdown alternate via an HTTP `Link` response header (RFC 8288), which crawlers can read without parsing HTML:

```
Link: <https://example.com/blog/my-post.md>; rel="alternate"; type="text/markdown"
```

This is controlled by the **Auto-inject Link Header** setting (on by default). It is emitted on both `GET` and `HEAD` requests, so header-only clients (uptime monitors, link checkers, `curl -I`) can discover the alternate too.

## Respecting SEO `noindex`

`noindex` is an explicit "don't surface this URL" signal, so LLM Ready honours it the same way a search engine would. If an entry is marked `noindex`, then:

- it is dropped from `/llms.txt` and from listing pages;
- its `.md` URL returns a 404;
- its canonical URL stops serving Markdown to AI bots and to `Accept: text/markdown` requests, falling through to the normal HTML response;
- its HTML page stops advertising a Markdown alternate, in both the `<link rel="alternate">` tag and the `Link` header — there is no point pointing crawlers at a URL that 404s.

This is always on and has no setting. It is useful for redirect stubs, utility pages, and anything else you already keep out of search results.

Live preview is exempt, so authors can still check the Markdown version of a `noindex` entry from the control panel.

### Supported plugins

| SEO plugin | How robots is read |
|---|---|
| [SEOmatic](https://github.com/nystudio107/craft-seomatic) | Through SEOmatic's own resolver, so an entry that inherits `noindex` from its section or global meta bundle counts, not just one with a per-entry override |
| [Ether SEO](https://github.com/ethercreative/seo) | From the SEO field's `advanced.robots` value. The field is located by type, so there is nothing to configure |

Both the `noindex` and `none` directives count as `noindex` — `none` is shorthand for `noindex, nofollow`. `nofollow`, `noarchive` and `nosnippet` on their own do not, since they don't say "don't index this".

Other SEO plugins have no per-entry robots concept that maps cleanly onto this, so they are not consulted. If neither plugin is installed, nothing changes and no lookups run.

### Performance note

For SEOmatic sites, building `/llms.txt` runs SEOmatic's meta-container resolution once per listed entry (up to 50 per section) so that inherited `noindex` is caught. Results are memoised per request, and the generated `/llms.txt` is itself cached for **Cache TTL** seconds, so this cost is paid on a cache miss rather than on every request. Ether SEO is a plain field read and costs effectively nothing.

## Response headers

Markdown responses include the following headers:

| Header | Value | Purpose |
|--------|-------|---------|
| `Content-Type` | `text/markdown; charset=utf-8` | Correct MIME type per RFC 7763 with explicit charset to prevent encoding issues |
| `X-Robots-Tag` | `noindex` | Prevents search engines from indexing Markdown pages (configurable) |
| `Link` | `<url>; rel="canonical"` | Points to the HTML version of the page |

## Plugin settings

Configure LLM Ready from **Settings > Plugins > LLM Ready** in the Craft control panel.

### Global settings

| Setting | Default | Description |
|---------|---------|-------------|
| Enabled | `true` | Master switch for the entire plugin |
| Content Negotiation | `true` | Serve Markdown for `Accept: text/markdown` requests |
| AI Bot User-Agent Detection | `false` | Serve Markdown to known AI crawlers **on the canonical URL**. Off by default because the response then varies by `User-Agent`, which shared caches don't key on. Safe to enable for origin-only sites — see [Why User-Agent detection is off by default](#why-user-agent-detection-is-off-by-default) |
| Additional Bot User-Agents | `[]` | Custom user-agent strings to detect as AI bots |
| Content Selector | `main, article, [role="main"], .content, #content` | CSS selectors for extracting main content from HTML |
| Exclude Selector | `""` | CSS selectors for elements to strip before Markdown conversion (e.g. `.carousel, [data-nosnippet]`) |
| X-Robots-Tag: noindex | `true` | Add `noindex` header to Markdown responses |
| Auto-inject Discovery Tag | `true` | Inject `<link rel="alternate">` into HTML pages |
| Auto-inject Link Header | `true` | Add an HTTP `Link` response header (RFC 8288) pointing at the Markdown alternate. Useful for crawlers that inspect headers without parsing HTML |
| Cache TTL (seconds) | `3600` | How long to cache Markdown output (`0` to disable) |
| Enable llms.txt | `true` | Serve `/llms.txt` and `/.well-known/llms.txt`. Turn off to 404 the route — and stop the home page advertising it — while leaving `.md` URLs, content negotiation and discovery tags working |
| Site Description | `""` | Introduction text for the `/llms.txt` blockquote. A value containing `{` is rendered as a Craft object template with the site as `site`, so it can read a global set field (`{{ siteInfo.llmDescription }}`) that content editors manage outside project config. See [Letting editors manage the site description](#letting-editors-manage-the-site-description). |
| Description Field | `""` | Field handle to use for entry descriptions in `/llms.txt` and listing pages. Supports dot notation (e.g. `seo.seoDescription`), `()` method-call syntax (e.g. `metaData.getMetaDescription()`), Generated Field handles, and a native SEOmatic resolver via `seomatic:description`. See [SEO-PLUGINS.md](SEO-PLUGINS.md) for SEOmatic / Ether SEO / SEOmate / SEO Fields recipes. When set, the configured field is authoritative — no auto-extract fallback runs if it resolves to nothing. |
| Title Field | `""` | Optional field handle for the front-matter `title:` value. Supports the same syntax as Description Field (dot notation, `()` method calls, Generated Field handles, `seomatic:title`), or a Craft object template such as `{{ entry.longTitle ?: entry.title }}`. Falls back to the entry's native title when blank or unresolved. See [Customizing the title and author](#customizing-the-title-and-author). |
| Author Override | `""` | Author written to each entry's front matter. A fixed name (a team or company, say) replaces individual editor names on every entry. A Craft object template such as `{% if entry.section.handle == 'blog' %}{{ entry.authors\|map(a => a.fullName ?: a.username)\|join(', ') }}{% endif %}` is rendered per entry, and the `author:` line is omitted when it renders to nothing. Blank uses each entry's own authors, comma-separated on multi-author entries. See [Customizing the title and author](#customizing-the-title-and-author). |

### Section settings

Below the global settings, a per-section configuration table lists all sections that have URLs. For each section and site combination, you can configure:

| Column | Description |
|--------|-------------|
| Enabled | Toggle Markdown output on/off for this section. When disabled, `.md` requests return 404. |
| LLM Template | Optional path to a Twig template that outputs raw Markdown (e.g., `_llm/blog`). Leave blank to use automatic HTML-to-Markdown conversion. |

## Known AI bot user-agents

LLM Ready detects the following AI crawler user-agents by default:

- `GPTBot` (OpenAI — training)
- `ChatGPT-User` (OpenAI — user-requested fetch)
- `OAI-SearchBot` (OpenAI — search)
- `ClaudeBot` (Anthropic — training)
- `Claude-SearchBot` (Anthropic — search)
- `Claude-User` (Anthropic — user-requested fetch)
- `PerplexityBot` (Perplexity — answer index)
- `Perplexity-User` (Perplexity — user-requested fetch)
- `Meta-ExternalAgent` (Meta — AI training and indexing)
- `Meta-ExternalFetcher` (Meta — user-requested fetch)
- `Meta-WebIndexer` (Meta — web discovery index)
- `Amazonbot` (Amazon)
- `Bytespider` (ByteDance)
- `CCBot` (Common Crawl)
- `cohere-ai` (Cohere)

Matching is case-insensitive against any substring of the request's `User-Agent` header.

> **Note:** `Google-Extended` and `Applebot-Extended` are intentionally **not** in this list. They are robots.txt opt-out tokens for AI training, not request user-agents — they never appear in a `User-Agent` header, so detecting them would have no effect. Use them in `robots.txt`, not here.

### Customizing the detected bots

| Setting | Where | Effect |
|---------|-------|--------|
| `additionalBotUserAgents` | Control panel or config | **Appended** to the default list. Use for custom or internal crawlers. |
| `botUserAgents` | Config file only | **Replaces** the default list entirely. `additionalBotUserAgents` is still appended on top. |
| `excludeBotUserAgents` | Config file only | **Removes** entries from the effective list — drop a single default without re-listing the others. |

The effective list is computed as: `(botUserAgents or the defaults) + additionalBotUserAgents − excludeBotUserAgents`. Set the config-only options in `config/llm-ready.php` (see [`src/config.php`](src/config.php) for the template). For example, to keep the defaults but stop detecting `Bytespider`:

```php
// config/llm-ready.php
return [
    'excludeBotUserAgents' => ['Bytespider'],
];
```

### Blocking specific bots

LLM Ready does not manage which bots can access your site — it only detects known AI crawlers so it can serve them Markdown. To block a specific bot from crawling your site entirely, configure your `robots.txt` file. Most Craft CMS SEO plugins (e.g., SEOmatic, Sprout SEO) provide a `robots.txt` editor where you can add rules like:

```
User-agent: GPTBot
Disallow: /

User-agent: CCBot
Disallow: /
```

This tells the bot not to crawl any pages on your site. Note that `robots.txt` is a voluntary standard — well-behaved crawlers respect it, but it is not an access control mechanism. If you need to enforce a hard block, use server-level rules (e.g., in your web server or `.htaccess` configuration).

## Analytics

LLM Ready includes an opt-in analytics dashboard that tracks AI bot requests to your site. Enable it in the plugin settings under **Enable Analytics**.

Once enabled, a **LLM Ready** section appears in the control panel navigation with a dashboard showing requests over time, bot breakdown, most accessed pages, and request type breakdown.

### Permissions

Access to the analytics data is gated behind two user permissions under **LLM Ready** (set per user group, or per user, in **Settings > Users**):

- **View the analytics dashboard** — required to open the dashboard, its JSON data endpoint, and the dashboard widget.
- **Purge analytics data** (nested under the above) — required to manually purge analytics records.

Admins have both by default. Non-admin users have no access until granted.

### Dashboard widget

A compact **LLM Ready Analytics** widget is available from the Craft CP dashboard's **+ Add widget** menu. It shows the last 30 days of activity for the current site — total requests, top bot, top page — with a click-through to the full dashboard. The widget only appears in the picker when analytics are enabled and the current user has the **View the analytics dashboard** permission.

### Filtering by bot or request type

Switch the chart to the **By Bot** or **By Type** view using the toggle buttons, then click any legend item to filter the entire dashboard to that bot or type. When a filter is active:

- The chart shows only the selected item.
- The stats cards (Total Requests, Unique Bots, Content Types Served) update to reflect the filtered data.
- The bot breakdown, request types, and most accessed pages tables all update to show only data matching the filter.
- A **Filtered: [name]** indicator appears with a **Clear** button to remove the filter.

Click the same legend item again or press **Clear** to restore the full (unfiltered) view. Switching chart views or changing the date range also clears any active filter.

### Request types

The analytics dashboard groups requests into four types:

| Type | Meaning |
|------|---------|
| **entry** | A direct request for a specific entry's Markdown version via the `.md` URL suffix (e.g., `/blog/my-post.md`). This is the most common request type. |
| **listing** | A request for a section's listing page via the `.md` suffix (e.g., `/blog.md`), which returns a Markdown list of all entries in that section. |
| **llmstxt** | A request for the `/llms.txt` site index file. |
| **negotiated** | A request for a normal URL where the client sent an `Accept: text/markdown` HTTP header, and the plugin responded with Markdown instead of HTML. Some AI tools use this approach rather than appending `.md` to URLs. |

### What `direct` means

The bot breakdown labels a request `direct` when the `User-Agent` header that reached Craft was either empty or contained none of the strings in the effective bot list (see [Known AI bot user-agents](#known-ai-bot-user-agents)). It is a catch-all for "not a bot we recognise", not "a person typed the URL into a browser". Monitoring tools, scrapers and newer AI fetchers that aren't in the list all land here. If `direct` dominates your dashboard, your web server's access log filtered to `.md` and `llms.txt` requests shows the real user-agents, and any you want named can be added with `additionalBotUserAgents`.

One user-agent gets its own label. Requests that arrive as `Amazon CloudFront` are logged under that name rather than `direct`, because that value means CloudFront replaced the real user-agent before the request reached your origin. The dashboard shows a warning whenever the selected date range contains them. See [Every request is labeled `direct`, or the dashboard shows `Amazon CloudFront`](#every-request-is-labeled-direct-or-the-dashboard-shows-amazon-cloudfront) under Troubleshooting for the fix.

### Data retention

Analytics data is retained for a configurable number of days (default 90). You can purge old data manually from the dashboard or automatically via the console command:

```bash
./craft llm-ready/analytics/purge
```

## Caching

Markdown output is cached using Craft's cache component (Redis, database, or file-based depending on your configuration). The cache is automatically invalidated when:

- An entry is saved
- An entry is deleted
- A global set is saved (the `/llms.txt` cache only, since its Site Description can read one)

The cache TTL is configurable in the plugin settings. Set to `0` to disable caching entirely.

You can also clear the cache manually via **Utilities > Clear Caches > Data caches** in the control panel or by running:

```bash
./craft clear-caches/data
```

## Permissions and access control

LLM Ready respects Craft's content access rules:

- **Draft and disabled entries** are never served as Markdown.
- **Entries without URLs** are not available as Markdown (there's no URL to append `.md` to).
- Only entries with a `live` status are served.

## Multi-site support

LLM Ready supports Craft's multi-site feature. Each section can be independently enabled or disabled per site, and each site can have its own dedicated LLM template. The `/llms.txt` file is generated per-site, listing only entries belonging to the current site; a Site Description sourced from a global set field is per-site too.

## Troubleshooting

### `.md` URLs return 404

- Verify the plugin is installed and enabled: **Settings > Plugins > LLM Ready**.
- Check that the section is enabled in the plugin's section settings.
- Ensure the entry is published (status `live`) and has a URL.

### Markdown output includes navigation or footer content

Adjust the **Content Selector** in plugin settings to match your template's main content area. For example, if your content is in `<div class="article-body">`, set the selector to `.article-body`.

### Markdown output includes decorative or repeated text

If a region inside your main content area is purely decorative (e.g. a marquee, a logo carousel, or alt-less imagery), add a selector for it to **Exclude Selector**. Matching nodes are removed from the HTML before Markdown conversion, so they never reach the output. Selectors marked with the standard `[data-nosnippet]` attribute can also be excluded with `[data-nosnippet]`.

### Markdown output is empty or minimal

If the entry's template fails to render, LLM Ready falls back to extracting content from common field handles. For best results, either fix the template or configure a dedicated LLM template for that section.

### Cache not clearing

LLM Ready invalidates cache automatically on entry save. If you see stale content, clear the data cache manually:

```bash
./craft clear-caches/data
```

### Every request is labeled `direct`, or the dashboard shows `Amazon CloudFront`

`direct` means the `User-Agent` that reached Craft matched nothing in the bot list (see [What `direct` means](#what-direct-means)). When *every* request is `direct`, or a bot called `Amazon CloudFront` appears in the breakdown, something between the visitor and Craft is replacing the header and the plugin never sees the real one.

**Amazon CloudFront** is the usual cause. Unless a cache behavior is told to forward `User-Agent`, CloudFront removes the viewer's header and sends `User-Agent: Amazon CloudFront` to the origin ([AWS docs](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/RequestAndResponseBehaviorCustomOrigin.html#request-custom-user-agent-header)). LLM Ready logs those requests under the name `Amazon CloudFront` and shows a warning on the dashboard. The fix is to forward the header in the distribution's **origin request policy**, not its cache policy:

1. In the CloudFront console open your distribution, go to **Behaviors** and edit each behavior that serves the site.
2. Under **Cache key and origin requests**, choose **Cache policy and origin request policy** rather than **Legacy cache settings**.
3. Set **Origin request policy** to the managed **UserAgentRefererHeaders** policy (ID `acba4595-bd28-49b8-b9fe-13317c0390fa`), which forwards only `User-Agent` and `Referer`. **AllViewer** (`216adef6-5c7f-47e4-b989-5492eafa07d3`) also works if you already forward everything. See [managed origin request policies](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/using-managed-origin-request-policies.html).
4. Leave `User-Agent` out of the **cache policy**. There it becomes part of the cache key and every user-agent gets its own cached copy. An origin request policy forwards the header without that.

Legacy cache settings have no separate origin request policy. Whitelisting `User-Agent` there does put it in the cache key, so migrate to the policy pair instead.

To confirm the fix, send a request with a bot user-agent and a throwaway query string so it passes the CDN and any page cache, then reload the dashboard with a range that includes today:

```bash
curl -s -o /dev/null -A "GPTBot/1.0" "https://example.com/some-entry.md?llmready=$(date +%s)"
```

It should appear as `GPTBot`. Before the fix the same request appears as `Amazon CloudFront`, and your origin's access log shows that literal value in the user-agent column.

Two related points for CloudFront sites:

- CloudFront also strips the `Accept` header by default, so `Accept: text/markdown` content negotiation never reaches Craft. Don't fix that by forwarding `Accept`. CloudFront keys its cache only on the cache policy and ignores the plugin's `Vary` header, so negotiated Markdown for a canonical URL would be cached at the edge and served to browsers. Behind CloudFront, rely on the `.md` URLs and `/llms.txt`, which have their own cache keys.
- Bots that hit a `.md` URL already cached at the edge never reach Craft, so the dashboard counts origin fetches, not total bot traffic.

**Other proxies.** Any reverse proxy or WAF that sets its own `User-Agent` (an nginx `proxy_set_header User-Agent ...` rule, for example) has the same effect. The access log on the Craft server shows what actually arrives.

**Blitz.** If Blitz's `cacheNonHtmlResponses` setting is on, its cache generator regenerates `.md` URLs itself. The Local Generator sends no user-agent and the HTTP Generator sends `amphp/http-client`, so both are logged as `direct`. The default (`false`) avoids this.
