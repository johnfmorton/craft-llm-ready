# Using LLM Ready with SEO Plugins

LLM Ready's **Description Field** setting controls the descriptive text shown:

- After each entry link in `/llms.txt`
- After each entry link on listing-page Markdown output

By default, LLM Ready auto-extracts a description from the first text field it finds on the entry. If you already maintain SEO descriptions in another plugin, you almost certainly want LLM Ready to use those instead — the editorial work is already done, and you get consistency between what search engines see and what AI crawlers see.

This document shows the exact value to enter in the Description Field setting for each major Craft 5 SEO plugin.

## TL;DR

| SEO plugin | Composer handle | Approach | Description Field value |
|---|---|---|---|
| [Ether SEO](https://github.com/ethercreative/seo) | `ether/seo` | Direct dot notation | `seo.description` |
| [SEO Fields](https://github.com/studioespresso/craft-seo-fields) | `studioespresso/craft-seo-fields` | Method call via dot notation | `metaData.getMetaDescription()` |
| [SEOmatic](https://github.com/nystudio107/craft-seomatic) | `nystudio107/craft-seomatic` | Generated Field recipe | The handle you give the Generated Field |
| [SEOmate](https://github.com/vaersaagod/seomate) | `vaersaagod/seomate` | Generated Field recipe | The handle you give the Generated Field |

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

The dotted path can be arbitrarily deep. Each segment can be a property name or a `()` method call. The final value is converted to plain text (HTML stripped, whitespace collapsed). If anything in the chain returns null, LLM Ready treats the description as empty — there is no silent fallback to auto-extraction when Description Field is explicitly set.

## 1. Ether SEO

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

## 2. SEO Fields (Studio Espresso)

**Set Description Field to: `<your SEO field handle>.getMetaDescription()`**

If your SEO Fields field has the handle `metaData`, enter:

```
metaData.getMetaDescription()
```

The `()` at the end is required. Read on for why.

### Why the parens matter

SEO Fields' field returns a `SeoFieldModel`. That model has **both** a public property called `$metaDescription` AND a method called `getMetaDescription()`. They do different things:

| Path | What you get |
|---|---|
| `metaData.metaDescription` *(no parens — DON'T use this)* | The raw editor override only. Empty for most entries. |
| `metaData.getMetaDescription()` *(with parens — use this)* | The resolved description: override → element behavior overrides → site default. |

This is a PHP quirk: when a real public property exists, PHP property access bypasses the same-named getter method. The `()` syntax in Description Field tells LLM Ready to call the method explicitly.

### Things to know

- If your `/llms.txt` shows no descriptions for SEO-Fields entries, double-check you have the parens. This is the most common cause.
- SEO Fields enforces one SEO field per layout, so there's no ambiguity about which field is "the" SEO field.

## 3. SEOmatic

SEOmatic doesn't expose a single PHP property that returns the fully resolved description. Resolution happens inside SEOmatic's meta-containers system, which needs an element + site context loaded first. The cleanest way to bridge this to LLM Ready is a **Generated Field**.

### Setup

1. Open the field layout for the section(s) you want to expose to LLM Ready.
2. Add a **Generated Field** to the layout. Give it a unique handle — `llmDescription` is fine.
3. Paste this as the Generated Field's template:

   ```twig
   {% do craft.seomatic.containers.loadMetaContainers(object.uri, object.siteId, object) %}
   {{ seomatic.meta.seoDescription }}
   ```

4. Save the field layout.
5. In **LLM Ready** settings, set **Description Field** to:

   ```
   llmDescription
   ```

   (Use whatever handle you actually gave the Generated Field.)

### What the template does

- Line 1 asks SEOmatic to load the full meta data for this specific entry on its current site: global meta → section meta bundle → the per-entry SEO Settings field, with any Twig field tokens resolved.
- Line 2 outputs `seoDescription` — exactly what SEOmatic would render in `<meta name="description">`.

### Things to know

- **Entries without a URI won't resolve.** Drafts and entries in URL-less sections produce nothing.
- **There's a real cost** to `loadMetaContainers` — it walks bundles and parses Twig. LLM Ready caches Markdown output (see **Cache TTL** in plugin settings), so this fires once per entry per cache cycle, not on every request.
- **Site context is handled for you.** LLM Ready renders Markdown in the entry's own site context, and the template passes `object.siteId` explicitly, so multi-site sites resolve descriptions against the right site.
- If you also want to expose `ogDescription` or `twitterDescription`, swap them in on line 2 (e.g. `{{ seomatic.meta.ogDescription }}`).

### Alternative: per-entry SEO Settings only (faster, no global merging)

If you only care about the description the editor typed into the per-entry SEO Settings field — no template fallbacks, no global merging — you can read the raw SeoSettings field directly:

```
<your SEO Settings field handle>.metaGlobalVars.seoDescription
```

This is fast (no container load), but the value will be empty for entries the editor didn't fill in, AND any Twig tokens like `{field.summary}` will appear verbatim. **Use the Generated Field recipe above unless you specifically know you don't need template resolution.**

## 4. SEOmate

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

## Multi-site notes

LLM Ready renders Markdown per site, so the site context is already correct when your Description Field is resolved. The Generated Field recipes above pass `object.siteId` (for SEOmatic) and `object` (for SEOmate) explicitly, so each site's descriptions resolve against that site's SEO data.

If you maintain different SEO setups per site, the recipes above will pick up whatever site-specific overrides the SEO plugin already supports.

## Mixing SEO sources across sections

Description Field is a single global setting. If one section uses SEOmatic and another uses a plain `summary` field, you have two options:

1. **Generated Field on every section** — give every section's field layout a Generated Field with the same handle (e.g. `llmDescription`). The template inside each Generated Field can be different: one section reads `craft.seomatic.meta.seoDescription`, another returns `object.summary`. Point Description Field at the shared handle. Each section produces its own description, but LLM Ready only sees one setting.

2. **One SEO source for `/llms.txt`** — pick the SEO setup that covers most sections, and accept that the others fall through to LLM Ready's empty-description behavior. Per-section Description Field configuration isn't supported today; [file an issue](https://github.com/johnfmorton/craft-llm-ready/issues) if you need it.

## Troubleshooting

**`/llms.txt` shows entry titles but no descriptions for a section:**

1. Verify the handle in **Description Field** matches what your SEO field is actually called in the entry's field layout. Field handles are case-sensitive.
2. For SEO Fields: confirm you included the `()` at the end. Without it, only the raw editor override is read.
3. Clear LLM Ready's cache — saving the plugin settings page triggers a cache flush.
4. Load an individual entry's `.md` URL in the browser. If the description appears there but not in `/llms.txt`, the per-entry rendering works and the issue is in your Generated Field or your SEO plugin's resolution. If it's missing in both, double-check the Description Field value.
5. For SEOmatic Generated Fields: temporarily render the Generated Field's template in a regular entry template (e.g. `{{ entry.llmDescription }}`) to confirm it produces text outside the LLM Ready code path.

**The description shows raw Twig tokens like `{field.summary}`:**

You're reading the SEOmatic SEO Settings field's raw `seoDescription` value, not the resolved one. Switch to the Generated Field recipe in section 3.

**The description is empty after the first `.md` request but populated on subsequent ones:**

Not a bug — LLM Ready's first-request render is slower because nothing's cached yet. Subsequent requests hit the cache. If first-render latency matters for your use case, pre-warm by visiting `/llms.txt` after a deploy or content change.
