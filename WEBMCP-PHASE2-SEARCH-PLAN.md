# Phase 2 planning — exposing a site's search through WebMCP

Status: **draft / design exploration.** Companion to
[WEBMCP-PLAN.md](WEBMCP-PLAN.md); this document deepens its Phase 2 into a
design any developer can follow to expose *their* site's search — whatever
shape it takes — as WebMCP tools.

---

## 1. The problem: no two sites search alike

The original Phase 2 sketch was a single `search-entries(query, section?)`
tool over Craft's search index. That works, but it flattens something
agents badly need: **what kind of thing each search returns.**

The motivating example (the plugin author's own blog):

- **Writing** — original essays and articles in a `blog` section. A search
  hit means "John wrote about this."
- **Saved links** — a curated library of *other people's* work: bookmarked
  articles, tools, and resources, each with notes. A hit means "John saved
  something about this," and the payload that matters is the *outbound*
  URL, not just the entry's own page.
- **Tags** — the saved links are also browsable by tag, so "what does this
  site have about CSS?" is answerable two ways: text search, or the `css`
  tag's collection.

One generic tool can return all of this, but the agent can't tell an
essay from a bookmark, doesn't know tags exist, and gets one mushy
description to reason with. And **no other site will have this exact
shape** — a recipe site has ingredients and cuisines, a documentation site
has versions, an agency site has case studies filtered by industry. The
plugin cannot enumerate these. What it *can* do is give every developer
the same small vocabulary for describing theirs.

## 2. The core abstraction: search scopes

> **A search scope is a named, agent-described, queryable slice of a
> site's content.**

Each scope declares four things:

| Part | What it is | Who consumes it |
|---|---|---|
| **Handle** | Short identifier (`writing`, `saved-links`) | Becomes the tool name: `search-writing`, `search-saved-links` |
| **Description** | Agent-facing prose: what this search covers, what a result *is*, when to use it | The agent — this is the UX |
| **Facets** *(optional)* | Extra typed parameters beyond the text query (`tag`, `year`, `section`) and how each maps to criteria | The tool's `inputSchema` |
| **Source** | How matches are produced — entry criteria (declarative) or a PHP resolver (code) | The plugin's search endpoint |

Everything else — tool registration, the JSON endpoint, input validation,
visibility rules, result formatting, caching, analytics — is the plugin's
job, identical for every scope. **The developer describes; the plugin
plumbs.** A developer who can fill in a four-part description has exposed
their search; they never write JavaScript, never touch the WebMCP API, and
never build an endpoint.

## 3. Three layers of involvement

The methodology is progressive: each layer is a superset of the one
before, and most sites stop at the first or second.

### Layer 0 — zero config (works at install)

With WebMCP + search enabled and nothing configured, the plugin ships
**one default scope**: `site` → a `search-site` tool searching all
WebMCP-eligible sections via Craft's search index (`Entry::find()
->search($q)`), with an auto-generated description naming the site and its
sections. This preserves the original Phase 2 behavior and means the
feature is never useless out of the box.

(Deliberately *not* one auto-scope per section: a site with twelve
sections would register twelve tools nobody described. Sections deserving
their own scope deserve a sentence of human description — that's Layer 1.)

### Layer 1 — declared scopes (config, no code)

Scopes defined in `config/llm-ready.php` (control panel UI later, if
demand). Declaring any scope replaces the default. The motivating blog:

```php
// config/llm-ready.php
'webMcpSearchScopes' => [
    'writing' => [
        'label' => 'Blog writing',
        'description' => "Search John's original essays and articles about "
            . 'web development, Craft CMS, and creative projects. Results '
            . 'are things John wrote.',
        'criteria' => [
            'section' => 'blog',
        ],
    ],
    'saved-links' => [
        'label' => 'Saved links',
        'description' => 'Search a curated library of links John saved from '
            . 'around the web — articles, tools, and resources, each with a '
            . "short note on why it's worth reading. Results point to the "
            . 'saved page and to the original external link.',
        'criteria' => [
            'section' => 'links',
        ],
        'facets' => [
            'tag' => [
                'type' => 'tag',
                'group' => 'linkTags',
                'description' => 'Filter to links tagged with this topic. '
                    . 'Omit to search all links.',
            ],
        ],
        'resultFields' => [
            'linkUrl' => 'Original link',
        ],
    ],
],
```

That config yields two tools — `search-writing` and `search-saved-links`
— each with its own crisp description, the second accepting an optional
`tag` parameter and including each hit's outbound URL in the results.
`criteria` accepts the entry-query params Craft developers already know
(section, type, relatedTo, custom field criteria), applied on top of the
plugin's non-negotiable gates (§6).

### Layer 2 — resolver scopes (PHP event, full control)

For search that isn't an entry query at all — an Algolia index, a
different element type, a computed collection — a module registers a
scope whose source is a callable:

```php
use johnfmorton\llmready\events\RegisterSearchScopesEvent;
use johnfmorton\llmready\models\SearchHit;
use johnfmorton\llmready\services\WebMcpService;
use yii\base\Event;

Event::on(
    WebMcpService::class,
    WebMcpService::EVENT_REGISTER_SEARCH_SCOPES,
    function(RegisterSearchScopesEvent $event) {
        $event->scopes['products'] = [
            'label' => 'Product catalog',
            'description' => 'Search the product catalog by name or keyword.',
            'resolve' => function(string $query, array $facets, int $limit): array {
                // Any search you can code: Algolia, Commerce, an API…
                return array_map(
                    fn($p) => new SearchHit(
                        title: $p->title,
                        url: $p->url,
                        summary: $p->shortDescription,
                        entryId: $p->entryId, // null for non-entry results
                        extra: ['price' => $p->priceLabel],
                    ),
                    MyCatalog::search($query, $limit),
                );
            },
        ];
    },
);
```

The resolver returns `SearchHit` objects; everything downstream (endpoint,
formatting, analytics, tool registration) is unchanged. Declared scopes
(Layer 1) are internally just resolvers the plugin writes for you — one
code path.

## 4. From scope to tool: the parts agents actually see

**One tool per scope, not one tool with a scope enum.** The description is
the agent's entire basis for choosing and using a tool, and per-scope
descriptions ("search John's essays" vs. "search John's saved links") are
categorically better copy than one tool whose description must explain
every scope. Registering a handful of well-described tools follows the
spec's own guidance; a settings cap (default ~6 scopes exposed, warn
beyond) keeps pathological configs from flooding the agent's context.
*Open question §9.1 keeps the enum alternative alive until real-agent
testing settles it.*

**Naming**: `search-` + handle, validated against the WebMCP name charset
(`[a-zA-Z0-9_.-]`, 1–128 chars); handles must be unique after prefixing.

**Descriptions are copy, not code.** The methodology's step most
developers will underinvest in is the one that matters most. The docs
should teach a template: *what the collection is, what a result
represents, when an agent should reach for it* — and explicitly say the
first person ("John's essays") is fine; agents relay it naturally.

**Facets become `inputSchema` properties.** `q` (string, optional when at
least one facet is provided — tag-browsing without a text query is a
legitimate search), `limit` (integer, capped server-side), plus declared
facets. A `tag`-type facet with a small tag group (≤ ~30) is emitted as a
JSON-Schema `enum` so agents see the actual vocabulary; larger groups fall
back to a free string matched server-side. Facet types for v1: `tag`
(tag group), `category` (category group), `field` (plain field=value
criteria), `year` (postDate range). Anything richer is a Layer 2 resolver.

## 5. The result envelope

Uniform across every scope, so the plugin — not each developer — owns
formatting:

- Endpoint returns JSON internally; the tool renders it for the agent as
  a compact Markdown list (consistent with everything else the plugin
  serves): title as a link, one-line summary, the `.md` URL when the hit
  is an entry with one, per-scope `resultFields` extras (the saved link's
  outbound URL), matched tags when a tag facet exists.
- `SearchHit`: `title`, `url`, `summary`, `entryId?`, `mdUrl?` (derived),
  `extra[]`, `tags[]`.
- Default 10 hits, `limit` capped at 25; zero hits returns an explicit
  "no matches for …" text (agents handle a stated miss far better than an
  empty string).

## 6. Non-negotiable gates (visibility parity)

Same rules as every other surface, enforced by the plugin, not the scope
author:

- Entry-backed hits (any hit carrying `entryId`) are re-verified before
  rendering: status live, section WebMCP-eligible, not `noindex`. A
  resolver cannot leak a hidden entry even if it tries.
- Hits without an `entryId` (external URLs, computed items) can't be
  auto-verified; the docs state plainly that such results are the
  developer's responsibility and must be public information. The anonymous
  view rule applies: the endpoint ignores login state and preview tokens.
- Endpoint hygiene: GET, `allowAnonymous`, query length capped (~200
  chars), facet values validated against declarations, results
  `Cache-Control: no-store` (searches are long-tail; caching buys little
  and risks staleness), `X-Robots-Tag: noindex`. Cost is bounded — Craft's
  search index or the developer's own resolver — and the exposure is the
  same class as the public site search form most of these sites already
  have; a per-IP rate limit is noted as an open question rather than
  built preemptively.

## 7. Wire format: how a scope travels

PHP is the single source of intelligence; the JS stays generic.

1. At page render, the plugin builds one tool descriptor per exposed
   scope (name, description, inputSchema from facets) plus the endpoint
   URL, into the existing `llm-ready-webmcp` data island.
2. The bootstrap script grows one generic capability: register a
   fetch-backed tool whose inputs become query parameters
   (`/actions/llm-ready/webmcp/search?scope=…&q=…&tag=…`). It needs no
   knowledge of what any scope means — new scopes never change the JS.
3. The endpoint resolves the scope, validates input, runs the resolver,
   applies §6, renders the Markdown envelope, logs analytics.

Search tools register on every page (like `get-site-overview`) — a
site's search isn't page-specific.

## 8. Analytics

Every search call logs `source = webmcp` with the tool name and query
length (not the query text — visitor searches routed through an agent are
still visitor behavior; logging raw queries is a privacy decision the
plugin shouldn't make silently). Dashboard slice: calls per tool, zero-hit
rate per tool — the zero-hit rate is the signal that a scope description
is promising something the index can't deliver.

## 9. Open questions

1. **One tool per scope vs. one tool with a scope enum.** Decided above
   for per-scope tools on description-quality grounds, but it's cheap to
   A/B against a real agent in the Phase 2 validation pass (same site,
   both shapes, which does the agent drive better?). Revisit only if
   testing contradicts.
2. **Markdown list vs. structured JSON-as-text results.** Markdown reads
   well and matches the plugin's voice; some agents may parse structure
   more reliably. Validate alongside 9.1.
3. **Rate limiting** the public endpoint — ship without, watch, add if
   abused? Or a cheap fixed ceiling per IP per minute from day one?
4. **Multi-site**: scopes defined per site via Craft's multi-environment
   config is workable; is a `sites` key on the scope worth adding?
5. **Tag browsing without search**: does the `tag` facet suffice for the
   saved-links case, or does a dedicated `list-tags` tool earn its context
   cost? Lean: facet enum suffices when the group is small (the enum *is*
   the tag list); revisit for large vocabularies.
6. **CP UI for scopes** — config-file only at first (scope descriptions
   are code-reviewable copy; project config sync comes free). A settings
   UI only if non-developer demand appears.

## 10. Build order

1. `SearchHit` model + scope normalization (declared → resolver) +
   `RegisterSearchScopesEvent`.
2. `WebMcpController::actionSearch` with gates, envelope, analytics.
3. Descriptor generation into the data island + the generic fetch-tool
   registration in `webmcp.js`.
4. Default `site` scope; facet types (`tag` first — it's the motivating
   case); caps and validation.
5. Docs: the methodology chapter (inventory your searches → describe each
   as a scope → pick a layer → write the description as copy → test via
   `getTools()` and a real agent), with the blog example as the worked
   tutorial.
6. Validation pass with a real agent: open questions 9.1, 9.2, 9.5.

---

*Aside for later phases: nothing in the scope abstraction is
WebMCP-specific. A scope is "a described, queryable slice of the site" —
the same registry could back a server-side MCP endpoint, an OpenAPI
description, or whatever transport the agent ecosystem settles on next.
Designing scopes as the stable core and WebMCP as one consumer keeps that
door open without building anything extra today.*
