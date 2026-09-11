# WebMCP support — FAQ

Draft FAQ for the WebMCP feature, written from the questions plugin users are
most likely to ask. Companion to [plan.md](plan.md).

---

### What is WebMCP, in plain terms?

A web standard (in progress, driven by Google and Microsoft through the W3C)
that lets a web page offer "tools" to an AI agent running in the visitor's
browser. A tool is a named action with a description and typed inputs — like
"get this page's content" or "search this site" — that the agent can call
directly instead of scraping the page and guessing. It's the browser-native
cousin of the Model Context Protocol (MCP) used by desktop AI apps.

### How is this different from what LLM Ready already does?

Everything LLM Ready does today serves AI systems that fetch your URLs from
outside — crawlers reading `.md` URLs, `/llms.txt`, content negotiation.
WebMCP serves the agent *inside your visitor's browser tab*, during a real
visit. Same content, same rules, different audience. The two channels share
one configuration: sections enabled for Markdown are the sections whose
content WebMCP tools can serve.

### Do I need this?

Not yet, for most sites — and it's off by default. Turn it on if you want
your site to be a first-class citizen for agentic browsing as it arrives, or
if your audience skews toward tools like ChatGPT Desktop and Copilot Mode in
Edge. The cost of enabling is one setting plus an origin trial token; the
cost of waiting is zero, because nothing else in the plugin depends on it.

### Which browsers and agents support it today?

As of late 2026: Chrome 149+ and Edge 150+ via origin trial, ChatGPT
Desktop's built-in browser, and experimental support in Brave's Leo. Firefox
and Safari are in standards discussions and have shipped nothing. Every other
visitor is unaffected — the plugin's script checks for the API once and does
nothing when it's absent.

### What's an "origin trial token" and why do I need one?

Chrome and Edge gate pre-release web APIs behind origin trials: a site owner
registers their domain at Chrome's origin trials console, gets a token, and
serves it in a meta tag; the browser then enables the API for that site's
visitors. The plugin has a setting for the token and injects the tag for you.
Tokens are free, per-origin, and expire — multi-site installs on different
domains need one per site. For local development you can skip the token and
flip `chrome://flags#enable-webmcp-testing` instead (Chrome 150+); Google's
[WebMCP Model Context Tool Inspector](https://chromewebstore.google.com/detail/webmcp-model-context-tool/gbpdfapgefenggkahomfgkhfehlcenpd)
extension then lists the page's tools, runs them by hand, or hands them to
Gemini. When the API ships unflagged, the token step disappears.

### Will it slow down my site?

No measurable effect. The addition is a ~2 KB script plus a small JSON
configuration block. On browsers without WebMCP the script exits after one
feature check. There are no external requests, no polling, and nothing runs
until an agent actually calls a tool.

### Can an AI agent change my content or take actions on my site through this?

No. Every tool the plugin ships is read-only — a structured way of reading
content that is already public. The plugin registers nothing that submits
forms, writes data, or acts on the visitor's behalf. (A later release will
let *your own* modules register custom tools, which could include actions —
but that's code you write and a responsibility the docs are explicit about.
See "Can I add my own tools?" below.)

### Could this expose drafts, disabled entries, or noindex pages?

No. Tools enforce exactly the checks that `.md` serving enforces: enabled
sections, `live` status, SEO `noindex` respected. On top of that, tool
endpoints always render the anonymous view and ignore preview tokens — so
even when a logged-in editor's browser agent calls a tool, it sees what an
anonymous visitor sees. Nothing is reachable through a tool that isn't
already reachable at a public URL.

### What about prompt injection?

Tool responses are your published content, verbatim — the same Markdown a
crawler gets from the `.md` URL, with no added instructions. Whether an agent
treats page content as trustworthy is decided by the agent and browser, and
guarding against injection is an active part of the WebMCP standardization
work. Enabling the plugin's tools doesn't give your content any capability it
doesn't already have in front of an agent that reads pages.

### Does it work with Blitz, Cloudflare, or other caches?

Yes, cleanly. The script and configuration are part of the page's HTML and
identical for every visitor, so full-page and CDN caching work unchanged.
This is unlike User-Agent detection (which the plugin ships off by default
for cache-safety reasons) — there is no per-visitor variation for a cache to
mishandle.

### Does it affect SEO?

No. Nothing about your HTML content, URLs, or crawl behavior changes. The
one addition to your markup is a script tag and a JSON block, which search
engines ignore. Tool endpoints carry the same `X-Robots-Tag: noindex`
treatment as the plugin's Markdown responses.

### Does it work on multi-site installs?

Yes, with the same per-section, per-site enablement as the rest of the
plugin. The one extra consideration is origin trial tokens, which are
per-origin — sites on different domains each need their own token. In
`config/llm-ready.php` the setting takes an array keyed by site handle.

### I run Craft headless. Can I use this?

Not out of the box — the plugin injects tools when Craft renders your Twig
pages, and a headless front end never hits that path. A headless front end
can register its own WebMCP tools directly (and could call the plugin's
content endpoints from them), but that's your front end's code.

### What happens if the WebMCP spec changes?

The plugin isolates every API touch in one small adapter that
feature-detects the current surface. When browsers move — and during origin
trials they do — the adapter is updated in a plugin release; your templates
and settings don't change. The setting stays default-off until the standard
stabilizes precisely so that churn stays the plugin's problem, not yours.

### What if Firefox and Safari never ship it?

Then the feature keeps working where it works and remains invisible
everywhere else — that's the progressive-enhancement design. Your crawler
surface (`.md`, `/llms.txt`) is unaffected either way. Chrome plus Edge plus
ChatGPT Desktop is already a meaningful share of agentic browsing, and the
per-site cost of participation is close to zero.

### Can I choose which tools are exposed?

Yes. The master switch controls injection overall; search and listing tools
get their own toggles; and per-section enablement (shared with Markdown
output) controls which content any tool can reach.

### Will I see agent tool usage in the analytics dashboard?

Yes — planned for the same release as the search tool. Tool calls that hit
the plugin's endpoints are logged as a **webmcp** request type alongside the
existing entry/listing/llmstxt/negotiated breakdown. Page-content reads go
through the existing `.md` URLs and are counted there.

### Can I add my own tools?

That's the Phase 3 extensibility layer: a PHP event where your module
registers tool descriptors (backed by a controller action URL or your own
JavaScript handler), plus a JS-side adapter (`window.llmReady.modelContext`)
so custom tools share the plugin's feature detection.

This is arguably where WebMCP gets most interesting. The plugin's own tools
cover reading content — but the best custom-tool candidates are pages whose
value is *computed or interactive* data that no scraper (and no content
tool) can reach: an availability calendar, a store-locator map, a shipping
estimator, a product configurator. One tool call exposes what's otherwise
locked inside a JavaScript widget.

For tools that act rather than read, the pattern the docs will recommend is
**prepare, human commits**: the tool prefills a complex form (a quote
request, a booking) from the agent conversation but never submits — your
visitor reviews and clicks send. Most of the value of an action tool, almost
none of the risk. Fully mutating tools remain possible but are your code,
your CSRF handling, and your call.

If you have a tool in mind — store hours, product filtering, booking
availability — that use case is wanted now, while the API is being designed.

### What about e-commerce — could an agent manage a cart on a Craft Commerce site?

That's the scenario we think shows WebMCP at its best, and it's on the
roadmap as a possible Craft Commerce tool pack after the extensibility
layer ships: checking variant availability, reading the cart, adding to it,
estimating shipping, and prefilling checkout — with safe defaults (reads
and prefills on by default, cart writes an explicit opt-in, payment always
completed by the human). Because the tools run in the visitor's own browser
session, the cart the agent builds is the visitor's actual cart, updating
in the page they're watching — they delegate the tedious middle and still
personally click Pay. If you run Commerce sites and want this, say so:
demand is what moves it up the list.

### Does enabling WebMCP cost anything or require an account?

No account with anyone, no API keys, no external services. The origin trial
token is free from Chrome's console (registering takes a Google account, as
their console requires sign-in) and goes away once the API ships. Everything
runs on your own site.
