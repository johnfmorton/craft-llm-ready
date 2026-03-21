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

**Required:** Craft CMS 5.5.0 or later. If the version is below 5.5.0, inform the developer that LLM Ready requires Craft CMS 5.5.0+.

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
> - `<link rel="alternate">` discovery tags in HTML pages
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
curl -s {entry_url} | grep 'rel="alternate" type="text/markdown"'
```

**Expected:** A `<link>` tag with `rel="alternate"` and `type="text/markdown"` pointing to the `.md` URL.

**Note:** This test will only pass if the entry's HTML template renders successfully. If the template has errors or the page returns a non-200 status, the discovery tag will not be injected.

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
> If you have a field that stores entry summaries (e.g., `summary`, `excerpt`), also set the **Description Field** to that handle so each entry in `/llms.txt` includes a brief description."

---

## Step 9: Offer dedicated Markdown template creation

Ask the developer:

> "LLM Ready can serve Markdown two ways:
>
> 1. **Automatic conversion** (current) — Renders your normal HTML template and converts it to Markdown. Works out of the box but may include unwanted elements.
> 2. **Dedicated LLM templates** — You create a separate Twig template per section that outputs raw Markdown. Gives you full control over what LLMs see.
>
> Would you like me to create dedicated Markdown templates for any of your sections?"

If the developer declines, you are done.

If the developer wants dedicated templates, proceed to Step 10.

---

## Step 10: Create dedicated LLM templates

For each section the developer wants a template for:

### 10a. Examine the existing template

Find the section's current template path. You can find this in:
- The Craft control panel under **Settings > Sections > [Section Name] > Site Settings > Template**
- Or by reading the project config YAML files in `config/project/sections/`

Read the existing template to understand what fields are used and how content is structured.

### 10b. Identify the entry's fields

Look at the section's entry type(s) to find the available field handles. Check:
- `config/project/entryTypes/` YAML files for field layouts
- Or read the entry type definitions to find field handles like `body`, `summary`, `featuredImage`, etc.

### 10c. Create the LLM template

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

### 10d. Configure the template in plugin settings

Inform the developer:

> "The template has been created at `templates/_llm/blog.twig`. To activate it, go to **Settings > Plugins > LLM Ready** and set the **LLM Template** field for the Blog section to `_llm/blog`."

Note: This setting must be configured in the Craft control panel — it is stored in the plugin's database table, not in project config files.

### 10e. Test the dedicated template

Clear the cache and test:

```bash
{cmd} craft clear-caches/data
curl -s {entry_url}.md
```

Verify that the Markdown output now reflects the dedicated template's structure rather than the automatic HTML conversion.

---

## Step 11: Optional — Configure plugin settings

If the developer wants to customize the plugin beyond defaults, here are the available settings at **Settings > Plugins > LLM Ready**:

| Setting | Default | What it does |
|---------|---------|--------------|
| Enabled | On | Master switch for the entire plugin |
| Content Negotiation | On | Respond to `Accept: text/markdown` headers |
| AI Bot Detection | On | Auto-detect AI crawler user-agents |
| Content Selector | `main, article, [role="main"], .content, #content` | CSS selectors for extracting main content during HTML conversion |
| X-Robots-Tag: noindex | On | Prevent search engines from indexing Markdown pages |
| Auto-inject Discovery Tag | On | Add `<link rel="alternate">` to HTML pages |
| Cache TTL | 3600 seconds | How long to cache Markdown output (0 = no cache) |
| Site Description | (empty) | Intro text for the `/llms.txt` blockquote |
| Description Field | (empty) | Field handle for entry descriptions in `/llms.txt` links (auto-extracts if blank) |
| Additional Bot User-Agents | (empty) | Custom user-agent strings to detect |

Per-section settings (enable/disable and LLM template) are configured in the table at the bottom of the settings page.

**Important:** After installation, prompt the developer to configure the **Site Description** setting. This text appears as a blockquote in `/llms.txt` and helps LLMs understand what the site is about. Suggest something like:

> "[Site Name] is a [type of site] by [author/org] covering [topics]."

If the site has a field that stores entry summaries or descriptions (e.g., `summary`, `excerpt`, `description`), also recommend setting the **Description Field** to that handle so `/llms.txt` entries include brief descriptions.

---

## Troubleshooting

If any tests fail, check these common issues:

| Symptom | Cause | Fix |
|---------|-------|-----|
| `.md` URL returns 404 | Plugin not installed, section disabled, or entry not published | Verify plugin is enabled and entry is live |
| Markdown output is empty | Template rendering failed, no content fields found | Check template for errors; create a dedicated LLM template |
| Markdown includes nav/footer | Content selector doesn't match the template's main content area | Update the Content Selector setting to target the correct element |
| `/llms.txt` is empty | No sections have URLs enabled | Check that sections have URI formats configured |
| Discovery tag missing | HTML page didn't render successfully, or setting is disabled | Check the entry's HTML page loads correctly |
| Content negotiation not working | Setting is disabled, or the Accept header is incorrect | Verify `enableContentNegotiation` is on and header is `Accept: text/markdown` |
