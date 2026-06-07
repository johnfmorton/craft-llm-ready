# Using LLM Ready with SEO Plugins

LLM Ready's **Description Field** setting controls the descriptive text shown:

- After each entry link in `/llms.txt`
- After each entry link on listing-page Markdown output

By default, LLM Ready auto-extracts a description from the first text field it finds on the entry. If you already maintain SEO descriptions in another plugin, you almost certainly want LLM Ready to use those instead — the editorial work is already done, and you get consistency between what search engines see and what AI crawlers see.

This document shows the exact value to enter in the Description Field setting for each major Craft 5 SEO plugin. Plugins are listed in order of popularity per the [Craft Plugin Store SEO category](https://plugins.craftcms.com/categories/seo?craft5).

## TL;DR

| SEO plugin | Composer handle | Approach | Description Field value |
|---|---|---|---|
| [SEOmatic](https://github.com/nystudio107/craft-seomatic) | `nystudio107/craft-seomatic` | Native resolver (full fallback chain) | `seomatic:description` |
| [Ether SEO](https://github.com/ethercreative/seo) | `ether/seo` | Direct dot notation | `seo.description` |
| [SEOmate](https://github.com/vaersaagod/seomate) | `vaersaagod/seomate` | Generated Field recipe | The handle you give the Generated Field |
| [SEO Fields](https://github.com/studioespresso/craft-seo-fields) | `studioespresso/craft-seo-fields` | Direct dot notation | `seo.metaDescription` (per-entry) or `seo.siteDefault.defaultMetaDescription` (site default) |

Two notes before you start:

1. **Replace the example field handles** (`seo`, `metaData`) with whatever handle YOUR SEO field actually uses in YOUR field layout. SEO plugin field handles are project-defined.
2. **Description Field is a global setting.** All sections share it. If different sections use different SEO setups, see [Mixing SEO sources across sections](#mixing-seo-sources-across-sections) at the bottom.

## How Description Field resolves values

Before getting into per-plugin recipes, it helps to know the four things LLM Ready will try based on what you type in:

| You enter | LLM Ready does |
|---|---|
| `summary` | Reads the custom field with handle `summary` from the entry |
| `seo.seoDescription` | Reads field `seo`, then a property named `seoDescription` on the result |
| `metaData.getMetaDescription()` | Reads field `metaData`, then calls the `getMetaDescription()` method on the result |
| `llmDescription` (a Generated Field handle) | Reads the Generated Field, which is just a Twig template you control |
| `seomatic:description` | Invokes the native SEOmatic resolver (see SEOmatic section below) |

The dotted path can be arbitrarily deep. Each segment can be a property name or a `()` method call. The final value is converted to plain text (HTML stripped, whitespace collapsed). If anything in the chain returns null, LLM Ready treats the description as empty — there is no silent fallback to auto-extraction when Description Field is explicitly set.

## 1. SEOmatic

**Set Description Field to: `seomatic:description`**

That's it. No Generated Field, no field-handle discovery, no `metaGlobalVars` path. The literal string `seomatic:description` is a recognized prefix that tells LLM Ready to call SEOmatic's resolver directly.

### What you get

The **full SEOmatic resolution chain** for every entry, in order:

1. Per-entry SEO Description (the override editors type into the SEO Settings field)
2. Section / entry-type meta bundle default
3. Global meta default
4. Twig field token parsing (e.g. `{field.summary}` resolves against the entry)

This is the same value SEOmatic would render in `<meta name="description">` on the entry's front-end page. Entries with no per-entry override fall through to your section default; sections with no section default fall through to your global meta default.

### Other meta fields

| Description Field value | Returns |
|---|---|
| `seomatic:description` | `<meta name="description">` (the `seoDescription` field) |
| `seomatic:og-description` | OpenGraph `og:description` |
| `seomatic:twitter-description` | `twitter:description` |

### Things to know

- **No field-layout changes required.** LLM Ready calls SEOmatic's PHP API directly when it builds `/llms.txt`. You don't need to add a Generated Field or wire anything up.
- **SEOmatic must be installed and enabled.** If SEOmatic isn't present, LLM Ready silently produces no description (and logs a warning). It won't error.
- **Entries without a URI won't resolve** (drafts, URL-less sections). LLM Ready returns no description for those.
- **Performance.** The first `/llms.txt` request after an entry change pays the cost of SEOmatic's container load. LLM Ready caches the result for the duration of your **Cache TTL** setting, so subsequent requests are cheap.
- **Multi-site.** LLM Ready hands SEOmatic the entry's site ID explicitly, so each site's `/llms.txt` resolves descriptions against that site's SEO data.

### Alternative: per-entry only, no fallback

If you specifically want to expose **only** what editors typed into the per-entry SEO Settings field — with no section/global fallback — use direct dot notation instead:

```
<your SEO Settings field handle>.metaGlobalVars.seoDescription
```

This is faster (no container load) but limited: entries the editor didn't fill in produce no description, and Twig tokens aren't parsed. Most users want the full chain — use `seomatic:description` unless you have a specific reason not to.

## 2. Ether SEO

**Set Description Field to: `<your SEO field handle>.description`**

If your Ether SEO field has the default handle `seo`, enter:

```
seo.description
```

### Why this works

Ether SEO's field returns a `SeoData` object. The `description` property on that object isn't a stored value — it's a [magic getter](https://github.com/ethercreative/seo/blob/v5/src/models/data/SeoData.php#L28) that runs the resolution logic, returning the per-entry override if the editor filled it in, or otherwise rendering the description template you configured in the SEO plugin's settings.

You get the same string that Ether SEO would output in `<meta name="description">`.

### Things to know

- The value can include light HTML (e.g. entity-encoded characters from your template). LLM Ready strips tags and decodes entities automatically.
- If you want the raw editor override only (no template fallback), use `seo.descriptionRaw` instead. Most users want the resolved value — stick with `seo.description`.

## 3. SEOmate

SEOmate is purely config-driven and doesn't ship a field type. The cleanest bridge is a **Generated Field** that calls SEOmate's Twig API.

### Setup

1. Open the field layout for the section(s) you want to expose to LLM Ready.
2. Add a **Generated Field**. Handle suggestion: `llmDescription`.
3. Template:

   ```twig
   {{ craft.seomate.getMeta({ element: object }).meta.description ?? '' }}
   ```

4. Save the field layout.
5. In **LLM Ready** settings, set **Description Field** to:

   ```
   llmDescription
   ```

### What the template does

`craft.seomate.getMeta({ element: object })` asks SEOmate to resolve the meta array for this specific entry using your configured `fieldProfiles` mapping. We then pull the `description` value, with `?? ''` so missing entries produce an empty string (rather than `null` strigified to `'null'`).

### Things to know

- If SEOmate has no `profileMap` entry for this section/type AND no `defaultProfile`, the result is empty — entries will appear without descriptions.
- Closures and complex object templates in your `fieldProfiles` config may assume a web request context. If you see warnings during queue jobs that re-save entries, simplify those mappings or move the logic into a regular field.

## 4. SEO Fields (Studio Espresso)

Pick the path that matches what you want LLM Ready to show:

| Goal | Description Field value |
|---|---|
| Per-entry meta descriptions only (blank for entries the editor didn't touch) | `seo.metaDescription` |
| Site-default description for every entry (ignores per-entry overrides) | `seo.siteDefault.defaultMetaDescription` |
| Per-entry override, falling back to site default if blank | Use the Generated Field recipe below |

Replace `seo` with your actual SEO field handle.

### The two direct paths

- **`seo.metaDescription`** — reads the raw editor override the editor typed into the SEO field on this entry. Empty for entries where the editor left it blank.
- **`seo.siteDefault.defaultMetaDescription`** — reads the per-site default you configured under **Settings → SEO Fields → Defaults**. Returns the same string for every entry that has the SEO field, regardless of the per-entry value.

### Override-then-fallback (recommended for most sites)

Most editorial setups want this: use the per-entry description when the editor typed one, otherwise fall back to the site default. SEO Fields exposes that resolution chain via `getMetaDescription()`, but that method is currently broken upstream (see [Why not `getMetaDescription()`?](#why-not-getmetadescription) below). The cleanest workaround is a Generated Field:

1. Add a **Generated Field** to your section's field layout. Handle: `llmDescription`.
2. Template:

   ```twig
   {{ object.seo.metaDescription ?: object.seo.siteDefault.defaultMetaDescription ?? '' }}
   ```

3. Set LLM Ready's **Description Field** to `llmDescription`.

### Why not `getMetaDescription()`?

SEO Fields' `SeoFieldModel` has both a public `$metaDescription` property (the editor's raw override) and a `getMetaDescription()` method that *should* return the resolved value with site-default fallback. In a perfect world, you'd just write `seo.getMetaDescription()` (using LLM Ready's `()` method-call syntax) and be done.

In practice, calling `seo.getMetaDescription()` currently throws a `Call to a member function getMetaDescription() on null` error inside SEO Fields itself ([`SeoFieldModel.php:231`](https://github.com/studioespresso/craft-seo-fields/blob/develop-v5/src/models/SeoFieldModel.php#L231)). The method dereferences `$this->element` without a null guard, and `SeoField::normalizeValue()` never populates that property when Craft hands you the field value. LLM Ready catches the exception, logs a warning, and produces no description for the entry — so your `/llms.txt` won't crash, but you also won't get a useful value.

**This is an upstream bug, not an LLM Ready issue.** If you'd like the method-call path to work in a future release, [open an issue with Studio Espresso](https://github.com/studioespresso/craft-seo-fields/issues) requesting:

1. A null guard on `$this->element` in `getMetaDescription()` (and the sibling `getMetaTitle`, `getSocialDescription`, etc.).
2. That `SeoField::normalizeValue()` set `$model->element = $element`, since the field has the element in hand.

### Things to know

- SEO Fields enforces one SEO field per layout, so there's no ambiguity about which field is "the" SEO field.
- The Generated Field recipe above works today and gives you the resolution chain you probably want.

## Multi-site notes

LLM Ready renders Markdown per site, so the site context is already correct when your Description Field is resolved. The native SEOmatic resolver receives the entry's site ID explicitly. The Generated Field recipes above pass `object` (which carries `siteId`) explicitly, so each site's descriptions resolve against that site's SEO data.

If you maintain different SEO setups per site, the recipes above will pick up whatever site-specific overrides the SEO plugin already supports.

## Mixing SEO sources across sections

Description Field is a single global setting. If one section uses SEOmatic and another uses a plain `summary` field, you have two options:

1. **Generated Field on every section** — give every section's field layout a Generated Field with the same handle (e.g. `llmDescription`). The template inside each Generated Field can be different: one section reads from SEOmatic via your own Twig, another returns `object.summary`. Point Description Field at the shared handle. Each section produces its own description, but LLM Ready only sees one setting.

2. **One SEO source for `/llms.txt`** — pick the SEO setup that covers most sections, and accept that the others fall through to LLM Ready's empty-description behavior. Per-section Description Field configuration isn't supported today; [file an issue](https://github.com/johnfmorton/craft-llm-ready/issues) if you need it.

## Troubleshooting

**`/llms.txt` shows entry titles but no descriptions for a section:**

1. Verify the handle in **Description Field** matches what your SEO field is actually called in the entry's field layout. Field handles are case-sensitive.
2. For SEO Fields: confirm you included the `()` at the end. Without it, only the raw editor override is read.
3. Clear LLM Ready's cache — saving the plugin settings page triggers a cache flush.
4. Load an individual entry's `.md` URL in the browser. If the description appears there but not in `/llms.txt`, the per-entry rendering works and the issue is in your Generated Field or your SEO plugin's resolution. If it's missing in both, double-check the Description Field value.
5. For SEOmate Generated Fields: temporarily render the Generated Field's template in a regular entry template (e.g. `{{ entry.llmDescription }}`) to confirm it produces text outside the LLM Ready code path.

**The SEOmatic description shows raw Twig tokens like `{field.summary}`:**

You're using the dot-notation alternative (`seoSettings.metaGlobalVars.seoDescription`) which returns SEOmatic's raw, unparsed value. Switch to `seomatic:description` to use the native resolver, which parses Twig tokens against the entry.

**The description is empty after the first `.md` request but populated on subsequent ones:**

Not a bug — LLM Ready's first-request render is slower because nothing's cached yet. Subsequent requests hit the cache. If first-render latency matters for your use case, pre-warm by visiting `/llms.txt` after a deploy or content change.

**I edited a Generated Field template (or my SEO plugin's defaults/config) and `/llms.txt` still shows the old descriptions:**

Saving an entry invalidates LLM Ready's caches for that entry automatically. Field-layout changes (like editing a Generated Field's template) and external-plugin config changes don't fire entry-save events, so the cache doesn't know to refresh. Run `craft clear-caches/all` or open and save the LLM Ready plugin settings page — both flush all caches.
