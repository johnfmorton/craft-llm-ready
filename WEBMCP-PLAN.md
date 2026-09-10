# WebMCP Support — Planning Document

Status: **draft / exploration** — nothing in this document is implemented yet.
Last researched: September 2026.

This document explores the WebMCP browser API and proposes how LLM Ready can
support it, so that Craft sites running the plugin become usable not just by
*crawling* agents (the plugin's current audience) but by *in-browser* agents
that act on the page the user is looking at.

---

## 1. What WebMCP is

[WebMCP](https://github.com/webmachinelearning/webmcp) is a W3C Web Machine
Learning CG proposal (driven by Google and Microsoft) that lets a web page
expose client-side functionality as **tools** — the same tool concept as the
[Model Context Protocol](https://modelcontextprotocol.io/), but registered in
JavaScript on the page rather than served by a backend MCP server. An AI agent
(the browser's built-in agent, a browser extension, or an agentic browser)
discovers the page's tools and calls them while the user watches, with the
page's UI staying live and in sync.

The key contrast with everything LLM Ready does today:

| | Current LLM Ready | WebMCP |
|---|---|---|
| Consumer | Server-side crawlers / fetchers (GPTBot, ClaudeBot, `curl`-style agent fetches) | Agents running **in the visitor's browser session** |
| Transport | HTTP: `.md` URLs, `Accept: text/markdown`, `/llms.txt` | JavaScript: tools registered on the page |
| Content | Static Markdown rendering of an entry | Structured actions: get content, search, navigate, (later) submit forms |
| State | Stateless, anonymous | Runs with the visitor's cookies/session, in their tab |

These are complementary, not competing. The plugin already knows how to turn
any entry into clean Markdown and how to index the whole site; WebMCP is a new
delivery channel for exactly that capability, plus a path to interactive
capabilities Markdown can't offer (search, structured queries).

### 1.1 The API surface

From the spec repo (`webmachinelearning/webmcp`) as of September 2026, the
entry point is **`document.modelContext`**:

```js
const controller = new AbortController();

await document.modelContext.registerTool({
  name: "add-todo",
  description: "Add a new item to the user's active todo list",
  inputSchema: {
    type: "object",
    properties: {
      text: { type: "string", description: "The text content of the todo item" }
    },
    required: ["text"]
  },
  async execute({ text }) {
    await addTodoItemToCollection(text);
    return {
      content: [{ type: "text", text: `Added todo item: "${text}" successfully.` }]
    };
  }
}, { signal: controller.signal });
```

Other relevant surface:

- `document.modelContext.getTools()` → promise of registered-tool descriptors
  (`name`, `description`, `inputSchema`, `origin`, owning `window`) — used by
  in-page agents.
- `document.modelContext.executeTool(tool, args, { signal })` — invoke a tool.
- A `toolchange` event fires when tools are added/removed/updated, so tools can
  be registered **dynamically per page state** (explicitly encouraged by the
  spec to keep agent context small).
- Results use the MCP content shape: `{ content: [{ type: "text", text }] }`.
- Registration accepts an `AbortSignal` for lifecycle/unregistration.
- Cross-origin: tools are visible to the top-level window and same-origin
  frames by default; an iframe needs `allow="tools"` and the registering page
  can scope with `exposedTo: [origin, …]`. **Not relevant to us in v1** — we
  register on the site's own pages for the site's own origin.
- TypeScript definitions exist as the `webmcp-types` npm package.

> **API churn warning:** earlier Chrome preview builds exposed
> `navigator.modelContext` with a `provideContext({ tools })` bulk call;
> the spec has since moved to `document.modelContext` / `registerTool`. The
> surface is still an origin-trial-stage API and **will keep moving**. Every
> line of our JS that touches the API must go through one small adapter
> (feature-detect `document.modelContext`, fall back to
> `navigator.modelContext`, silently no-op when neither exists). The rest of
> our code should never touch the global directly.

### 1.2 Implementation status (September 2026)

Per the spec repo's `implementation-status.md`:

- **Chrome 149** — Origin Trial live; local testing via
  `about:flags#enable-webmcp-testing`.
- **Edge 150** — Origin Trial live, mirroring Chrome.
- **ChatGPT Desktop** — supported (its embedded browser consumes page tools).
- **Brave** — experimental support in Leo AI chat.
- **Firefox / Safari** — standards-position discussions only, nothing shipped.

Practical consequences for the plugin:

1. **An Origin Trial token is required** for the API to be enabled for real
   visitors in Chrome/Edge. Tokens are per-origin and expire, so the *site
   owner* must obtain one; the plugin should make it easy to inject
   (`<meta http-equiv="origin-trial" content="…">`), not try to own it.
2. For visitors without a capable browser the API simply doesn't exist — our
   script must be a cheap, silent no-op there (a few hundred bytes decide and
   bail).
3. This is a progressive enhancement. Nothing in the existing crawler-facing
   feature set depends on it.

---

## 2. What LLM Ready can offer through WebMCP

The plugin's unique asset is that it already has a **machine-readable model of
the site**: per-section enablement, noindex awareness, clean Markdown for any
entry, listing pages, and a site index. Proposed tools, in order of value:

### Tier 1 — page-level, read-only (the MVP)

| Tool | Input | Behavior |
|---|---|---|
| `get-page-content` | *(none)* | Return the **current page's** Markdown — the same output as appending `.md`, but delivered in-context so the agent doesn't have to guess the URL or re-fetch/strip HTML. This alone is a headline feature: "the agent in your visitor's browser reads your page the way you authored it." |
| `get-site-overview` | *(none)* | Return `/llms.txt` content (or a trimmed version): what this site is, what sections exist, where the indexes are. |

Both are backed by responses the plugin already renders and caches; the JS
`execute` is essentially `fetch(currentUrl + '.md')` / `fetch('/llms.txt')`
plus wrapping in the `{ content: [...] }` shape.

### Tier 2 — site-level, read-only

| Tool | Input | Behavior |
|---|---|---|
| `list-entries` | `section?`, `page?`, `limit?` | Structured listing of live entries in WebMCP-enabled sections (title, URL, `.md` URL, date, description). Backed by a new JSON endpoint. |
| `search-entries` | `query`, `section?` | Craft's search (`Entry::find()->search()`) scoped to enabled sections, excluding noindex entries. Returns matches with URLs + descriptions so the agent can navigate or fetch `.md`. This is a capability crawling can never give — live search without a site-specific integration. |
| `get-entry` | `url` or `uri` | Markdown for an arbitrary entry by its URL — lets an agent follow a search hit without a page navigation. |

### Tier 3 — extensibility (developer-facing)

A way for **site developers** to register their own tools alongside ours.
The plugin's role here is plumbing: consistent registration, enable/disable,
and analytics — not guessing site-specific actions. See §3.4.

Tier 3 deserves more weight than "extensibility layer" suggests, because
**interactive tools are where WebMCP's deepest value sits** — it's the
headline use case in the spec's own examples and in the Chrome team's
framing (e-commerce, complex UIs). Two reasons:

1. **Acting is what agents can't fake.** Reading a page can be approximated
   by scraping — our read-only tools win on speed, cost, and accuracy, an
   efficiency story. Driving a page (a checkout, a booking flow) through the
   DOM is where agents currently fail outright: slow, brittle, broken by any
   markup change. A tool turns a twenty-click flow into two calls. And
   because the tool runs in the **visitor's own session**, the cart the
   agent builds *is* the user's cart, updating in the UI they're watching —
   the user delegates the tedious middle and still personally clicks Pay.
   Human-in-the-loop falls out of the architecture rather than being bolted
   on. A backend commerce API can't offer that without re-implementing auth
   and losing the shared screen.

2. **Widget-locked data.** Scraping fails hardest not on articles but on
   data that isn't in the HTML at all: a store-locator map, an availability
   calendar, a product configurator, a mortgage calculator. The answer lives
   behind JavaScript state or an XHR the agent can't see — content tools
   (ours included) can never reach it, and a WebMCP tool exposes it in one
   function. Pages whose value is *computed or interactive* data are the
   best Tier 3 candidates: `check-availability(date)`,
   `find-nearest-location(zip)`, `estimate-payment(price, term)`.

A pattern the Phase 3 docs should teach as the recommended middle ground:
**prepare, human commits** — a tool that *prefills* a complex form (a quote
request, an application) from the agent conversation but never submits; the
human reviews and clicks send. The agent writes to UI state, not to the
server: most of the value of a mutating tool with almost none of its risk,
and the safety posture we want developers to copy.

(Further out, and colliding deliberately with our v1 anonymous-view rule:
tools over **logged-in session data** — "when does my membership renew?" —
which no crawler can ever answer. That would be a developer's explicit
Phase 3 opt-in with its own security review, never plugin default.)

### Explicitly out of scope for now

- **Write/mutating tools** shipped by the plugin itself (form submission,
  commenting, cart operations). These need CSRF handling, per-tool user
  consent, and much more careful security review. The extensibility layer can
  let developers opt into building them; we don't ship any.
- Cross-origin tool exposure (`exposedTo`, `allow="tools"`).
- Draft/preview content. Tools see exactly what the `.md` URL sees: live
  entries, enabled sections, noindex respected.

---

## 3. Proposed architecture

The design follows the plugin's existing patterns: an opt-in setting, a
front-end injection hook, controller actions with the same visibility rules as
`.md` serving, project-config-friendly per-section control, and analytics.

### 3.1 Front-end injection

Mirror `registerDiscoveryTagInjection()` (src/LlmReady.php:440): on
`View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE` for site GET requests, when
`enableWebMcp` is on:

1. **Origin trial meta tag** (optional, from a new `webMcpOriginTrialToken`
   setting / env var):
   `<meta http-equiv="origin-trial" content="{token}">` — needed until the API
   ships unflagged; harmless to omit for flag-enabled testing.
2. **Bootstrap script** via a registered asset bundle (`WebMcpAsset`), not an
   inline script — inline JS would fight strict CSP setups. The page-specific
   context the script needs (current entry's `.md` URL, endpoint URLs, which
   tools are enabled, site handle) is passed as JSON in a
   `<script type="application/json" id="llm-ready-webmcp">…</script>` data
   island, which CSP treats as data, not code.

The bootstrap script (vanilla ES module, no build-step dependencies beyond
what the repo already tolerates):

- Feature-detects the API via the adapter (§1.1) and exits silently otherwise.
- Registers the enabled tools with descriptors generated from the JSON island.
- `execute` implementations call the plugin's endpoints with
  `Accept: application/json` / the `.md` URL and wrap results in
  `{ content: [{ type: "text", … }] }`.
- Registers page-appropriate tools only: `get-page-content` only when the
  current element resolves to an enabled, live, indexable entry (the PHP side
  decides this at render time — the same checks the discovery tag already
  performs — so the JS never needs to reason about it).

### 3.2 Server endpoints

New `WebMcpController` (siteside, `allowAnonymous`, GET-only), reusing
existing services:

- `llm-ready/webmcp/entries` — listing/search JSON. Enforces: plugin +
  WebMCP enabled, section enabled for the site, `Entry::STATUS_LIVE`,
  `SeoService::isNoindex()` exclusion — i.e. **exactly the visibility rules
  `.md` serving enforces** (see `MarkdownController::serveEntry()`), so WebMCP
  can never leak anything the `.md` surface wouldn't.
- `get-page-content` / `get-entry` need no new endpoint — they fetch the
  existing `.md` URLs, which keeps one code path, one cache, one set of rules.
- Responses are cacheable per-URL (query-string keyed), with the same
  `X-Robots-Tag: noindex` treatment as Markdown responses. Search responses
  should be short-TTL or uncached.

### 3.3 Settings

Additions to `Settings` (src/models/Settings.php), all following existing
conventions:

```php
/** @var bool Master switch for WebMCP tool injection (default false while the API is in origin trial) */
public bool $enableWebMcp = false;

/** @var string Chrome/Edge Origin Trial token to inject as a meta tag (config/env recommended) */
public string $webMcpOriginTrialToken = '';

/** @var bool Expose the search-entries tool (requires enableWebMcp) */
public bool $enableWebMcpSearch = true;

/** @var bool Expose the list-entries tool */
public bool $enableWebMcpListing = true;
```

Per-section control: reuse the existing project-config section settings
(`llm-ready.sectionSettings.{sectionUid}.{siteUid}`) — a section disabled for
Markdown is disabled for WebMCP, full stop. A separate per-section WebMCP
toggle is deferred until someone asks for it; adding a key to that config
shape later is a cheap schema bump (`schemaVersion` is already versioned).

Default **off** at launch: the API is origin-trial gated, and injecting a
script tag into every page of every site on plugin update would be a surprise.
Revisit the default when the API ships unflagged.

### 3.4 Extensibility (Tier 3)

Two layers, matching how the plugin already exposes extension points
(`DefineFrontMatterEvent`):

1. **PHP event** — `RegisterWebMcpToolsEvent` fired while building the JSON
   data island. A developer adds a tool *descriptor* (name, description,
   inputSchema) plus one of:
   - `actionUrl`: a Craft action/controller URL the generated JS will `fetch`
     with the tool's arguments (simple, covers most read-only cases), or
   - `jsHandler`: the name of a global function / module path the site's own
     JS defines; our bootstrap wires `execute` to it (full control, needed for
     anything that touches page UI state).
2. **JS escape hatch** — the adapter is exposed as
   `window.llmReady.modelContext` (register/unregister with the same
   feature-detection and no-op behavior), so a site can register bespoke tools
   without duplicating our detection code, and so tools registered either way
   are counted in analytics (below).

### 3.5 Analytics

The analytics table's `source` column already distinguishes `entry`,
`listing`, `llmstxt`, `negotiated`. Add `webmcp` (and log the tool name in the
existing path column, e.g. `tool:search-entries`), recorded server-side when a
tool's backing endpoint is hit — no new client beacons, no schema migration
beyond whatever validation exists on `source` values. Dashboard gets a
"WebMCP tool calls" slice for free through the existing group-by-source
queries. Tools that never hit the server (`jsHandler` custom tools) are
invisible to analytics in v1; acceptable.

### 3.6 Discovery

Just as the plugin advertises `.md` alternates, WebMCP-enabled pages need
nothing extra — tool discovery is the browser's job via the registered tools.
But we should document (and maybe emit) nothing that *promises* an agent tools
exist before the script runs; registration timing is handled by the API's
`toolchange` event on the agent side.

---

## 4. Security & correctness review points

- **Read-only in v1.** Every shipped tool is a GET with no side effects; no
  CSRF surface. The extensibility docs must warn that mutating tools are the
  developer's responsibility, need CSRF tokens on their action URLs, and run
  with the visitor's session.
- **Visibility parity.** WebMCP endpoints reuse `isSectionEnabled`,
  live-status, and `isNoindex` checks so tools can't expose content the `.md`
  surface hides. Add a test/checklist item: "everything reachable through a
  WebMCP tool is reachable through an existing public URL."
- **Session context.** Tools run in the visitor's browser with their cookies.
  Our endpoints must not vary output by login state (they render the same
  anonymous view the crawler surface renders) so a logged-in editor's agent
  can't be tricked into exfiltrating draft content. Concretely: WebMCP
  endpoints ignore preview tokens and always render the anonymous
  representation — note this deviates from `.md`'s preview-friendly behavior,
  deliberately.
- **Prompt-injection posture.** Content returned by tools is site content —
  same trust story as the `.md` URLs; nothing new to do, but worth a docs
  paragraph since "agent acts on page content" raises the question.
- **CSP.** Asset-bundle script + JSON data island; no inline event handlers,
  no `eval`. Document what a strict-CSP site needs to allowlist (the plugin
  asset bundle URL).
- **Cache safety.** The bootstrap script and data island are part of the HTML
  page and vary only by page — fully compatible with Blitz/CDN full-page
  caching (unlike UA sniffing, nothing varies by requester). Search endpoint
  responses must send appropriate short/no-cache headers.
- **Performance budget.** Target: bootstrap ≤ ~2 KB gzipped, zero work before
  feature detection, no registration on browsers without the API.

---

## 5. Phased roadmap

**Phase 0 — Spike (no release).**
Prototype on a test site with the Chrome 149 flag: adapter + hardcoded
`get-page-content` tool. Goals: confirm the real API surface Chrome ships vs.
the spec (§1.1 churn), measure script cost, try ChatGPT Desktop as a second
client. Output: a short findings note appended to this document.

**Phase 1 — MVP release (minor version).**
`enableWebMcp` setting (default off) + origin-trial token setting +
`WebMcpAsset` bootstrap + `get-page-content` and `get-site-overview` tools +
documentation (a WebMCP section in DOCUMENTATION.md explaining tokens, flags,
and which agents can use it). No new endpoints — both tools fetch existing
URLs.

**Phase 2 — Site tools.**
`WebMcpController` JSON endpoints; `search-entries` and `list-entries` tools;
analytics `source = webmcp`; per-tool settings toggles.

**Phase 3 — Extensibility.**
`RegisterWebMcpToolsEvent` + `window.llmReady.modelContext` escape hatch +
docs and examples. This is not a nice-to-have layer: it is the point where
the plugin becomes the plumbing for WebMCP's most valuable scenarios
(interactive and transactional tools — see Tier 3 in §2) while site
developers own the actions and their risk. The docs must lead with a
worked **prepare-human-commits** example (form prefill, no submit) as the
pattern to copy, alongside the "mutating tools are on you" security
guidance.

**Phase 4 — Track the standard.**
Re-check spec/OT status each release; when Chrome ships unflagged, consider
default-on, drop the token machinery, and revisit declarative/form-based tools
if that part of the proposal lands.

**Future direction — Craft Commerce tool pack.**
The strongest concrete instance of Tier 3 is a Craft Commerce store:
`check-variant-availability`, `get-cart`, `add-to-cart`,
`estimate-shipping`, and a prepare-human-commits checkout prefill. Rather
than leaving every agency to invent these (and their safety posture) from
the raw Phase 3 event, ship them as a first-party module or companion
plugin with safe defaults: cart *reads* and form *prefills* on by default,
cart *writes* an explicit opt-in, payment always human-committed. Depends
on Phase 3 being solid and on real demand from Commerce sites — validate
via the Phase 3 feedback ask before building. Tracked here so the Phase 3
API is designed with a demanding first customer in mind.

---

## 6. Open questions

1. **Chrome's shipped surface vs. spec** — does the 149 OT accept
   `document.modelContext.registerTool` exactly as specced, and what does
   `webmcp-types` currently pin? (Phase 0 answers this; npmjs.com was not
   reachable from the research environment.)
2. **Origin trial token ergonomics** — token per origin, multi-site Craft
   installs need per-site tokens; setting should therefore be
   per-site-overridable via `config/llm-ready.php` (the config file already
   supports env-driven overrides).
3. **Tool naming** — namespace tools (`llm-ready:get-page-content` vs bare
   names)? Bare, verb-first names match the spec's guidance and read better to
   agents; collision risk with site-registered tools is handled by documenting
   that the plugin's names are reserved.
4. **Listing pages** — should `get-page-content` also register on section
   listing URLs (backed by `renderListingPage`)? Probably yes in Phase 2,
   using the same section-resolution logic as `MarkdownController`.
5. **Headless/hybrid sites** — sites using Craft as a headless CMS never
   render Twig pages, so injection never fires. Non-goal, but the docs should
   say so.
