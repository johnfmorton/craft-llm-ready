# LLM Ready Documentation

LLM Ready serves Markdown versions of your Craft CMS pages to AI crawlers and large language models. It works out of the box with zero template changes — install the plugin and your content is immediately available as Markdown.

> **Using an AI coding assistant?** Point your agent to [AI-INSTALL.md](AI-INSTALL.md) for a step-by-step installation and configuration runbook designed for Claude Code, Codex, Gemini, and similar tools.

## How it works

LLM Ready intercepts requests for Markdown content using three detection methods:

1. **`.md` URL suffix** — Append `.md` to any entry URL (e.g., `/blog/my-post.md`). This is the primary method used by AI crawlers in practice.
2. **Content negotiation** — Requests with an `Accept: text/markdown` header receive Markdown instead of HTML.
3. **AI bot user-agent detection** — Known AI crawlers (GPTBot, ClaudeBot, etc.) automatically receive Markdown responses.

When a Markdown request is detected, LLM Ready resolves the entry from the URL, converts its content to Markdown, and serves it with appropriate headers.

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

## /llms.txt

LLM Ready auto-generates a `/llms.txt` file following the [llms.txt specification](https://llmstxt.org/). This file serves as a site index for LLMs, listing all enabled sections with links to each entry's Markdown version.

The generated file includes:

- **H1**: Your site name
- **Blockquote**: An optional site description (configured in plugin settings)
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
| AI Bot User-Agent Detection | `true` | Serve Markdown to known AI crawlers |
| Additional Bot User-Agents | `[]` | Custom user-agent strings to detect as AI bots |
| Content Selector | `main, article, [role="main"], .content, #content` | CSS selectors for extracting main content from HTML |
| X-Robots-Tag: noindex | `true` | Add `noindex` header to Markdown responses |
| Auto-inject Discovery Tag | `true` | Inject `<link rel="alternate">` into HTML pages |
| Cache TTL (seconds) | `3600` | How long to cache Markdown output (`0` to disable) |
| Site Description | `""` | Introduction text for the `/llms.txt` blockquote |

### Section settings

Below the global settings, a per-section configuration table lists all sections that have URLs. For each section and site combination, you can configure:

| Column | Description |
|--------|-------------|
| Enabled | Toggle Markdown output on/off for this section. When disabled, `.md` requests return 404. |
| LLM Template | Optional path to a Twig template that outputs raw Markdown (e.g., `_llm/blog`). Leave blank to use automatic HTML-to-Markdown conversion. |

## Known AI bot user-agents

LLM Ready detects the following AI crawler user-agents by default:

- `GPTBot` (OpenAI training)
- `ChatGPT-User` (OpenAI live browsing)
- `OAI-SearchBot` (OpenAI search)
- `ClaudeBot` (Anthropic)
- `Claude-Web` (Anthropic)
- `Amazonbot` (Amazon)
- `Bytespider` (ByteDance)
- `CCBot` (Common Crawl)
- `Google-Extended` (Google AI)
- `FacebookBot` (Meta)
- `PerplexityBot` (Perplexity)
- `Applebot-Extended` (Apple)
- `cohere-ai` (Cohere)

Add custom user-agent strings in the plugin settings under **Additional Bot User-Agents**.

## Caching

Markdown output is cached using Craft's cache component (Redis, database, or file-based depending on your configuration). The cache is automatically invalidated when:

- An entry is saved
- An entry is deleted

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

LLM Ready supports Craft's multi-site feature. Each section can be independently enabled or disabled per site, and each site can have its own dedicated LLM template. The `/llms.txt` file is generated per-site, listing only entries belonging to the current site.

## Troubleshooting

### `.md` URLs return 404

- Verify the plugin is installed and enabled: **Settings > Plugins > LLM Ready**.
- Check that the section is enabled in the plugin's section settings.
- Ensure the entry is published (status `live`) and has a URL.

### Markdown output includes navigation or footer content

Adjust the **Content Selector** in plugin settings to match your template's main content area. For example, if your content is in `<div class="article-body">`, set the selector to `.article-body`.

### Markdown output is empty or minimal

If the entry's template fails to render, LLM Ready falls back to extracting content from common field handles. For best results, either fix the template or configure a dedicated LLM template for that section.

### Cache not clearing

LLM Ready invalidates cache automatically on entry save. If you see stale content, clear the data cache manually:

```bash
./craft clear-caches/data
```
