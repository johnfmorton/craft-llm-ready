# AI Agent Installation Guide — LLM Ready for Craft CMS

> **This file is written for AI coding assistants** (Claude Code, Codex, Gemini, etc.) to follow as a step-by-step runbook. If you are a human developer, see [README.md](README.md) and [DOCUMENTATION.md](DOCUMENTATION.md) instead.

Follow the steps below in order. Each step includes what to check, what to run, and what to confirm before moving on.

---

## Step 1: Detect the environment

Before running any commands, determine whether this project uses DDEV for local development.

**Check:** Look for a `.ddev/config.yaml` file at the project root.

- **If `.ddev/config.yaml` exists:** Prefix all `composer` and `craft` commands with `ddev` (e.g., `ddev composer`, `ddev craft`).
- **If it does not exist:** Use bare commands (`composer`, `./craft`).

The rest of this guide uses `{cmd}` as a placeholder. Replace it with `ddev` or nothing depending on what you detected above.

---

## Step 2: Check prerequisites

Run these checks and stop if any fail. Report the failure to the developer.

### 2a. Verify Craft CMS version

```bash
{cmd} craft version
```

**Required:** Craft CMS 5.9.18 or later. If the version is below 5.9.18, inform the developer that LLM Ready requires Craft CMS 5.9.18+ (the minimum was raised to pull in Craft's security fixes) — a lower version will make `composer require` fail.

### 2b. Verify PHP version

```bash
{cmd} php -v
```

**Required:** PHP 8.2 or later.

### 2c. Check if LLM Ready is already installed

```bash
{cmd} craft plugin/list
```

Look for `llm-ready` in the output. If the plugin is already listed and enabled, inform the developer:

> "The LLM Ready plugin is already installed and enabled. Would you like me to help configure it, create dedicated Markdown templates, or test the existing setup?"

If it is listed but disabled, ask the developer if they'd like you to enable it:

```bash
{cmd} craft plugin/enable llm-ready
```

If it is not listed, proceed to Step 3.

---

## Step 3: Get permission to install

**Do not run install commands without confirmation.** Present this message to the developer:

> "I'd like to install the **LLM Ready** plugin (`johnfmorton/craft-llm-ready`). This plugin serves Markdown versions of your Craft CMS pages to AI crawlers and LLMs. It adds:
>
> - `.md` URL suffix support (e.g., `/blog/my-post.md`)
> - Content negotiation via `Accept: text/markdown` header
> - Automatic AI bot user-agent detection (GPTBot, ClaudeBot, etc.)
> - A `/llms.txt` site index file
> - `<link rel="alternate">` discovery tags (and an optional HTTP `Link` header) in HTML pages
>
> This will run `composer require` and a database migration to create one table (`llmready_section_settings`). Shall I proceed?"

Wait for the developer to confirm before continuing.

---

## Step 4: Install the plugin

Run these commands in order:

```bash
# Install the package via Composer
{cmd} composer require johnfmorton/craft-llm-ready

# Install the plugin in Craft (runs the database migration)
{cmd} craft plugin/install llm-ready
```

Verify the install succeeded:

```bash
{cmd} craft plugin/list
```

Confirm that `llm-ready` appears in the list with status `Yes` (enabled).

---

## Step 5: Determine the site URL

You need the site's base URL to run tests. Find it using one of these methods:

- **DDEV projects:** Run `ddev describe` and look for the project URL (e.g., `https://my-project.ddev.site`).
- **Other setups:** Check the `PRIMARY_SITE_URL` or `CRAFT_SITE_URL` in the `.env` file, or read `config/general.php` for the site URL.

Store this as `{site_url}` for the test commands below.

---

## Step 6: Find a testable entry

You need a live entry with a URL to test the Markdown output. Find one:

```bash
# List recent entries with their URIs
{cmd} craft entrify/list --limit=5 2>/dev/null || true
```

If the above command is not available, check the `/llms.txt` endpoint which lists all entries with URLs:

```bash
curl -s {site_url}/llms.txt
```

Pick any entry URL from the output to use as `{entry_url}` in the tests below. If `/llms.txt` returns entries, the plugin is already partially working.

---

## Step 7: Run verification tests

Run all of these tests and report the results to the developer.

### 7a. Test `.md` URL suffix

```bash
curl -s -D - {entry_url}.md
```

**Expected:**
- HTTP status: `200`
- `Content-Type: text/markdown; charset=utf-8`
- `X-Robots-Tag: noindex`
- Body starts with YAML front matter (`---`) followed by Markdown content

### 7b. Test content negotiation

```bash
curl -s -D - -H "Accept: text/markdown" {entry_url}
```

**Expected:** Same as 7a — Markdown response with front matter.

### 7c. Test AI bot user-agent detection

```bash
curl -s -D - -A "ClaudeBot/1.0" {entry_url}
```

**Expected:** Same as 7a — Markdown response with front matter.

### 7d. Test `/llms.txt`

```bash
curl -s -D - {site_url}/llms.txt
```

**Expected:**
- HTTP status: `200`
- `Content-Type: text/markdown; charset=utf-8`
- Body contains the site name as an H1 heading, followed by sections with entry links

### 7e. Test discovery tag injection

```bash
curl -s {entry_url} | grep 'type="text/markdown"'
```

**Expected:** A `<link>` tag with `rel="alternate"` and `type="text/markdown"` pointing to the `.md` URL. Note that the attribute order may vary (e.g., `type` before `rel`), so the grep matches on `type="text/markdown"` rather than a fixed attribute sequence.

**Note:** This test requires that the entry's HTML template includes a `<head>` element. Craft automatically injects registered head tags before the closing `</head>` tag. If the template has no `<head>` element, or if the page returns a non-200 status, the discovery tag will not appear.

### 7f. Test the HTTP `Link` discovery header

```bash
curl -s -I {entry_url} | grep -i '^link:'
```

**Expected:** A `Link` header advertising the Markdown alternate, e.g. `Link: <{entry_url}.md>; rel="alternate"; type="text/markdown"`. Sending this via `curl -I` (a `HEAD` request) also confirms the header is present on `HEAD` responses, which header-only crawlers, uptime monitors, and link checkers rely on. This is controlled by the **Auto-inject Link Header** setting (on by default).

---

## Step 8: Present results to the developer

Summarize the test results. Example:

> "LLM Ready is installed and working. Here's what I verified:
>
> - `.md` URL suffix: Working — `{entry_url}.md` returns Markdown with front matter
> - Content negotiation: Working — `Accept: text/markdown` header returns Markdown
> - AI bot detection: Working — ClaudeBot user-agent receives Markdown
> - `/llms.txt`: Working — site index lists X entries across Y sections
> - Discovery tag: Working/Not working — `<link rel="alternate">` tag is/is not present in HTML pages
>
> The plugin is using automatic HTML-to-Markdown conversion for all sections. Would you like me to help create dedicated Markdown templates for any sections, or is the default conversion acceptable?
>
> **Recommended next step:** Go to **Settings > Plugins > LLM Ready** and set the **Site Description** field. This appears as a blockquote in `/llms.txt` and helps LLMs understand what your site is about. For example: '[Site Name] is a [type of site] by [author/org] covering [topics].'
>
> If your entries carry descriptions — in an SEO plugin (SEOmatic, Ether SEO, SEOmate, SEO Fields) or a plain field like `summary`/`excerpt` — you can surface them after each `/llms.txt` link. I'll walk through wiring that up next."

---

## Step 9: Detect the SEO plugin and configure descriptions

LLM Ready's **Description Field** and **Title Field** settings can pull text straight from an SEO plugin the site already uses, so AI crawlers see the same descriptions and titles as search engines. Find out what the site has, then recommend the right value.

### 9a. Detect the SEO plugin

```bash
{cmd} craft plugin/list
```

Look for any of these in the output:

| SEO plugin | Plugin handle | Recommended Description Field value |
|---|---|---|
| SEOmatic | `seomatic` | `seomatic:description` (native resolver — no field handle needed) |
| Ether SEO | `seo` | `<your SEO field handle>.description` (e.g. `seo.description`) |
| SEOmate | `seomate` | A Generated Field handle (see `SEO-PLUGINS.md`) |
| SEO Fields (Studio Espresso) | `seo-fields` | `<your SEO field handle>.metaDescription` |

The matching **Title Field** value follows the same syntax (e.g. `seomatic:title`); leave Title Field blank to keep using the entry's native title.

### 9b. Ask the developer

Confirm before changing anything — field handles are project-specific, and Description Field is a single global setting shared by all sections:

> "I found **[SEOmatic / Ether SEO / SEOmate / SEO Fields / no SEO plugin]** installed. LLM Ready can reuse those descriptions in `/llms.txt` and listing pages so they match what search engines see.
>
> - For SEOmatic, I'd set **Description Field** to `seomatic:description` — it uses SEOmatic's full per-entry → section → global fallback chain.
> - For the others, I need the actual handle of your SEO field in the entry's field layout. What is it?
>
> If you'd rather not use an SEO plugin, do your entries have a plain summary/excerpt field (e.g. `summary`, `excerpt`) I should point at instead? Leaving it blank auto-extracts from the first text field."

If **no SEO plugin** is detected, ask whether the developer keeps descriptions in a plain field and set **Description Field** to that handle, or leave it blank for auto-extraction.

### 9c. Apply and verify

Description Field and Title Field are set at **Settings > Plugins > LLM Ready** (or in `config/llm-ready.php`). After setting them, clear the cache and confirm a description now appears after the entry links:

```bash
{cmd} craft clear-caches/data
curl -s {site_url}/llms.txt
```

For the exact per-plugin recipes — including SEOmate's Generated Field setup and SEO Fields' override-then-fallback pattern — point the developer to **[SEO-PLUGINS.md](SEO-PLUGINS.md)**.

---

## Step 10: Offer dedicated Markdown template creation

Ask the developer:

> "LLM Ready can serve Markdown two ways:
>
> 1. **Automatic conversion** (current) — Renders your normal HTML template and converts it to Markdown. Works out of the box but may include unwanted elements.
> 2. **Dedicated LLM templates** — You create a separate Twig template per section that outputs raw Markdown. Gives you full control over what LLMs see.
>
> Would you like me to create dedicated Markdown templates for any of your sections?"

If the developer declines, you are done.

If the developer wants dedicated templates, proceed to Step 11.

---

## Step 11: Create dedicated LLM templates

For each section the developer wants a template for:

### 11a. Examine the existing template

Find the section's current template path. You can find this in:
- The Craft control panel under **Settings > Sections > [Section Name] > Site Settings > Template**
- Or by reading the project config YAML files in `config/project/sections/`

Read the existing template to understand what fields are used and how content is structured.

### 11b. Identify the entry's fields

Look at the section's entry type(s) to find the available field handles. Check:
- `config/project/entryTypes/` YAML files for field layouts
- Or read the entry type definitions to find field handles like `body`, `summary`, `featuredImage`, etc.

### 11c. Create the LLM template

Create a Twig template in the `templates/_llm/` directory (or another directory the developer prefers). The template should:

- Output raw Markdown (not HTML)
- Use the `entry` variable which is passed automatically
- Include only the content that's useful for LLMs

**Example template for a Plain Text body field** at `templates/_llm/blog.twig`:

```twig
# {{ entry.title }}

*{{ entry.postDate|date('F j, Y') }}*{% if entry.author %} by {{ entry.author.fullName }}{% endif %}

{% if entry.summary is defined and entry.summary %}
{{ entry.summary }}

---

{% endif %}
{% if entry.body is defined and entry.body %}
{{ entry.body }}
{% endif %}
```

**Example template for a CKEditor field** (e.g., `articleContent`) at `templates/_llm/blog.twig`:

```twig
# {{ entry.title }}

*{{ entry.postDate|date('F j, Y') }}*{% if entry.author %} by {{ entry.author.fullName }}{% endif %}

{% if entry.articleContent|length %}
{% for chunk in entry.articleContent %}
{% if chunk.type == 'markup' %}
{{ chunk.getMarkdown()|raw }}
{% elseif chunk.type == 'entry' %}
{% set block = craft.app.entries.getEntryById(chunk.entryId) %}
{% if block %}
{% if block.type == 'markdown' %}
{{ block.markdown }}
{% elseif block.type == 'youtubeVideo' %}
[YouTube Video]({{ block.youtubeUrl }})
{% endif %}
{% endif %}
{% endif %}
{% endfor %}
{% endif %}
```

**Important notes for template creation:**
- The template receives a single variable: `entry` (a `craft\elements\Entry` object).
- The template output is served directly as the Markdown response — do not wrap it in HTML layout tags.
- YAML front matter is prepended automatically by the plugin — do not add your own front matter block.
- **CKEditor fields:** CKEditor content is stored as typed chunks. Markup chunks are `craft\ckeditor\data\Markup` objects. Use `{{ chunk.getMarkdown()|raw }}` to output properly formatted Markdown. Do **not** use `{{ chunk }}`, `{{ chunk|striptags }}`, or `{{ chunk|raw }}` — these all produce plain text with no paragraph breaks, headings, or formatting. The `getMarkdown()` method (available since CKEditor plugin 4.8.0) converts the HTML into clean Markdown.
- For plain text fields, output the value directly with `{{ entry.fieldHandle }}`.
- For Redactor fields, the field value is HTML. Use `{{ entry.fieldHandle|striptags|raw }}` or let the automatic converter handle it.

### 11d. Configure the template in plugin settings

Inform the developer:

> "The template has been created at `templates/_llm/blog.twig`. To activate it, go to **Settings > Plugins > LLM Ready** and set the **LLM Template** field for the Blog section to `_llm/blog`."

Note: This setting must be configured in the Craft control panel — it is stored in the plugin's database table, not in project config files.

### 11e. Test the dedicated template

Clear the cache and test:

```bash
{cmd} craft clear-caches/data
curl -s {entry_url}.md
```

Verify that the Markdown output now reflects the dedicated template's structure rather than the automatic HTML conversion.

---

## Step 12: Optional — Configure plugin settings

If the developer wants to customize the plugin beyond defaults, here are the available settings at **Settings > Plugins > LLM Ready**:

| Setting | Default | What it does |
|---------|---------|--------------|
| Enabled | On | Master switch for the entire plugin |
| Content Negotiation | On | Respond to `Accept: text/markdown` headers |
| AI Bot Detection | On | Auto-detect AI crawler user-agents |
| Additional Bot User-Agents | (empty) | Custom user-agent strings to detect, appended to the built-in list |
| Content Selector | `main, article, [role="main"], .content, #content` | CSS selectors for extracting main content during HTML conversion |
| Exclude Selector | (empty) | CSS selectors for elements to strip before conversion (e.g. `.carousel, [data-nosnippet]`) |
| X-Robots-Tag: noindex | On | Prevent search engines from indexing Markdown pages |
| Auto-inject Discovery Tag | On | Add a `<link rel="alternate">` tag to HTML pages |
| Auto-inject Link Header | On | Also advertise the Markdown alternate via an HTTP `Link` header (sent on GET and HEAD) |
| Cache TTL | 3600 seconds | How long to cache Markdown output (0 = no cache) |
| Site Description | (empty) | Intro text for the `/llms.txt` blockquote |
| Description Field | (empty) | Field/path for entry descriptions in `/llms.txt` and listings. Supports dot notation, `()` method calls, Generated Fields, and `seomatic:description` — see Step 9 and `SEO-PLUGINS.md`. Auto-extracts if blank |
| Title Field | (empty) | Field/path overriding the front-matter `title:` (same syntax as Description Field). Falls back to the entry title if blank |
| Author Override | (empty) | Fixed author name written to every entry's front matter, instead of the individual editor's name |

Per-section settings (enable/disable and LLM template) are configured in the table at the bottom of the settings page.

**Important:** After installation, prompt the developer to configure the **Site Description** setting. This text appears as a blockquote in `/llms.txt` and helps LLMs understand what the site is about. Suggest something like:

> "[Site Name] is a [type of site] by [author/org] covering [topics]."

For **Description Field** and the optional **Title Field**, see Step 9 — if the site uses an SEO plugin, point these at it (e.g. `seomatic:description`) rather than a plain field, so AI crawlers and search engines stay consistent. Use **Author Override** when you want a single editorial/team byline in the front matter instead of individual editors' names.

---

## Step 13: Optional — Enable analytics

LLM Ready includes an opt-in analytics dashboard that tracks AI bot requests — which bots, which pages, and request types over time. It is off by default.

### 13a. Enable it

Turn on **Enable Analytics** at **Settings > Plugins > LLM Ready** (or set `'enableAnalytics' => true` in `config/llm-ready.php`). Once enabled:

- An **LLM Ready** item appears in the CP navigation with the full dashboard (requests over time, bot breakdown, top pages, request types).
- A compact **LLM Ready Analytics** widget becomes available from the CP dashboard's **+ Add widget** menu.
- Logged data is retained for **Analytics Retention Days** (default 90).

### 13b. Grant permissions

Access is gated behind two permissions under **LLM Ready** (assigned per user group in **Settings > Users**):

- **View the analytics dashboard** — required for the dashboard, its JSON data endpoint, and the widget.
- **Purge analytics data** (nested under the above) — required to purge records.

Admins have both by default; grant them to any non-admin who needs access. Old data can be purged from the dashboard or via the console command:

```bash
{cmd} craft llm-ready/analytics/purge
```

---

## Troubleshooting

If any tests fail, check these common issues:

| Symptom | Cause | Fix |
|---------|-------|-----|
| `.md` URL returns 404 | Plugin not installed, section disabled, or entry not published | Verify plugin is enabled and entry is live |
| Markdown output is empty | Template rendering failed, no content fields found | Check template for errors; create a dedicated LLM template |
| Markdown includes nav/footer | Content selector doesn't match the template's main content area | Update the Content Selector setting to target the correct element |
| `/llms.txt` is empty | No sections have URLs enabled | Check that sections have URI formats configured |
| Discovery tag missing | Template has no `<head>` element, HTML page didn't render, or setting is disabled | Ensure the template includes a `<head>` element, and that the page loads correctly |
| Content negotiation not working | Setting is disabled, or the Accept header is incorrect | Verify `enableContentNegotiation` is on and header is `Accept: text/markdown` |
| `/llms.txt` shows titles but no descriptions | Description Field unset or points at the wrong handle | Set Description Field per Step 9 (e.g. `seomatic:description`); see `SEO-PLUGINS.md` |
| Analytics dashboard/widget not visible | Analytics disabled, or the user lacks the View permission | Enable **Enable Analytics** and grant the **View the analytics dashboard** permission (Step 13) |
