# WebMCP — draft documentation

> **Status: partially landed.** The Phase 1 portions of this draft are now
> real documentation — see the "WebMCP tools" section of
> [DOCUMENTATION.md](../../DOCUMENTATION.md#webmcp-tools), which supersedes this
> file for everything Phase 1 ships. This draft remains the reference for
> the Phase 2 sections (search/listing tools, analytics) and Phase 3
> (extensibility) that haven't landed yet.

---

## WebMCP tools

LLM Ready can expose your content as **WebMCP tools** — actions that an AI
agent running in your visitor's browser can discover and call on the page
itself. Where the rest of the plugin serves your content to AI *crawlers*
fetching URLs from the outside, WebMCP serves the agent sitting next to your
visitor: the browser's built-in assistant, ChatGPT Desktop's browser, or an
agentic browser like Edge Copilot Mode.

With WebMCP enabled, a capable browser on any enabled page gains these tools:

| Tool | What the agent gets |
|------|---------------------|
| `get-page-content` | The current page as clean Markdown — the same output as the page's `.md` URL, without the agent having to find that URL or scrape the HTML |
| `get-site-overview` | Your `/llms.txt` site index: what the site is, its sections, and where everything lives |
| `search-entries` *(Phase 2)* | Live search across your enabled sections, returning titles, URLs, and descriptions |
| `list-entries` *(Phase 2)* | A structured listing of entries in a section |

Everything is **read-only**. The tools expose exactly the content your `.md`
URLs already expose, under exactly the same rules — enabled sections only,
live entries only, `noindex` respected. If a URL wouldn't serve Markdown to a
crawler, no WebMCP tool will hand its content to an agent.

### Requirements

WebMCP is an emerging web standard, currently in **origin trial** — an
opt-in preview period browsers use for new APIs.

- **Chrome 149+ / Edge 150+** with an origin trial token (see below), or the
  `chrome://flags#enable-webmcp-testing` flag for local testing (Chrome 150+
  if you also want the inspector extension described under *Testing
  locally*).
- **ChatGPT Desktop** supports page tools in its built-in browser.
- **Brave** has experimental support in Leo.
- Firefox and Safari have not shipped the API. Visitors on those browsers are
  unaffected: the plugin's script detects the missing API and does nothing.

Because the API is still in trial, **WebMCP support is off by default**.
Enabling it is safe on any site — browsers without the API simply never see
the tools — but it is a deliberate opt-in while the standard settles.

### Quick start

1. Turn on **Enable WebMCP Tools** in the plugin settings.
2. Register your site's origin for the WebMCP origin trial at Chrome's
   [origin trials console](https://developer.chrome.com/origintrials/) and
   paste the token into the **Origin Trial Token** setting. (Skip this for
   local testing with the browser flag.)
3. Open any enabled entry page in Chrome with an agent active — ChatGPT
   Desktop's browser, or the inspector extension's Gemini mode described
   next — and ask it something about the page. It will read your authored
   Markdown through `get-page-content` instead of scraping your HTML.

### Testing locally

You don't need an agent to see the tools working:

1. In Chrome 150+ enable `chrome://flags#enable-webmcp-testing` and restart
   the browser. No origin trial token is needed while the flag is on.
2. Install Google's [WebMCP Model Context Tool Inspector](https://chromewebstore.google.com/detail/webmcp-model-context-tool/gbpdfapgefenggkahomfgkhfehlcenpd)
   extension. It lists the tools each page registers, lets you execute one
   by hand with arguments you type, and can hand the tools to Gemini so a
   real agent decides when to call them. Its author warns that it has no
   production security boundaries, so keep it on a development profile and
   don't browse untrusted sites with it enabled.
3. Open an enabled entry page. The inspector should list `get-page-content`
   and `get-site-overview`; run `get-page-content` and you should see the
   page's Markdown.

Or check from the browser console on an enabled page:

```js
const tools = await document.modelContext.getTools();
console.log(tools.map(t => t.name));
// → ["get-page-content", "get-site-overview", ...]
```

### What gets added to your pages

On enabled pages, LLM Ready injects:

- a small script (≈2 KB gzipped, served from the plugin's asset bundle) that
  feature-detects the WebMCP API and registers the tools;
- a `<script type="application/json">` data island carrying the page's tool
  configuration (the current entry's `.md` URL, endpoint URLs);
- optionally, the `<meta http-equiv="origin-trial">` token tag.

On browsers without WebMCP the script exits after one feature check. Nothing
about the page varies by visitor, so the injection is fully compatible with
Blitz, Cloudflare, and other full-page caches — unlike User-Agent detection,
there is nothing here a shared cache can get wrong.

Your templates need a `<head>` element (same requirement as the discovery
tag) and, if you run a strict Content-Security-Policy, your `script-src` must
allow the plugin's asset bundle URL. There is no inline JavaScript.

### Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Enable WebMCP Tools | `false` | Master switch. Injects the tool script on pages in enabled sections. Off by default while the API is in origin trial. |
| Origin Trial Token | `""` | Token from Chrome's origin trials console, injected as a `<meta>` tag. Per-origin — multi-site installs across different domains need a token per site, settable via `config/llm-ready.php`. |
| Enable Search Tool *(Phase 2)* | `true` | Expose `search-entries`. |
| Enable Listing Tool *(Phase 2)* | `true` | Expose `list-entries`. |

Per-section control reuses the existing **Section Settings** table: a section
disabled for Markdown output is disabled for WebMCP too. There is no separate
per-section WebMCP toggle.

### Security model

- **Read-only.** Every shipped tool is a GET with no side effects. The plugin
  registers nothing that can change content, submit forms, or act on the
  visitor's behalf.
- **Visibility parity with `.md`.** Tools enforce the same checks as Markdown
  serving: plugin enabled, section enabled for the site, entry status `live`,
  SEO `noindex` respected. Nothing is reachable through a tool that isn't
  already reachable at a public URL.
- **Always the anonymous view.** Tools run in the visitor's browser with the
  visitor's session, so the plugin's endpoints deliberately render the
  anonymous representation and ignore preview tokens. A logged-in editor's
  agent sees the same content an anonymous visitor would — drafts and
  pending changes are never exposed. (This is stricter than `.md` URLs, which
  allow drafts during live preview.)
- **Your content, verbatim.** Tool responses are your published content —
  the same text a crawler gets from the `.md` URL. The plugin adds no
  instructions of its own to tool output.

### Analytics *(Phase 2)*

When analytics are enabled, calls to the search and listing tools are logged
with the request type **webmcp** and appear in the dashboard alongside the
existing types. `get-page-content` and `get-site-overview` fetch the existing
`.md` and `/llms.txt` URLs, so those calls are counted under their existing
types (`entry`, `llmstxt`); the client is typically labeled `direct` since
the fetch carries the visitor's browser user-agent.

### Adding your own tools *(Phase 3)*

The plugin's tools cover reading content. Site-specific actions — filtering a
product listing, prefilling a form — are yours to define, and the plugin
provides the plumbing so you don't repeat its feature detection or lose
analytics:

```php
use johnfmorton\llmready\events\RegisterWebMcpToolsEvent;
use johnfmorton\llmready\services\WebMcpService;
use yii\base\Event;

Event::on(
    WebMcpService::class,
    WebMcpService::EVENT_REGISTER_TOOLS,
    function(RegisterWebMcpToolsEvent $event) {
        $event->tools[] = [
            'name' => 'get-store-hours',
            'description' => 'Get opening hours for a store location',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'Store location name'],
                ],
                'required' => ['location'],
            ],
            // The generated JS will POST the arguments to this action URL
            'actionUrl' => '/actions/my-module/stores/hours',
        ];
    }
);
```

For tools that must touch page state directly, register from JavaScript
through the plugin's adapter, which handles feature detection and API
differences for you:

```js
window.llmReady?.modelContext?.registerTool({ /* WebMCP tool descriptor */ });
```

> **Mutating tools are your responsibility.** A tool that changes anything
> runs with the visitor's cookies and session. Protect its action URL with
> Craft's CSRF token, validate permissions server-side as you would for any
> form, and think carefully before letting an agent trigger it. The plugin
> ships no mutating tools for exactly this reason.

### Placing the tag yourself

Automatic injection covers pages Craft renders through its page pipeline whose URL resolves to an entry. For anything else, turn off **Auto-inject WebMCP** and place the bootstrap where you want it:

```twig
{# in a layout: the site overview tool everywhere, the page tool on entry pages #}
{{ craft.llmReady.webMcp() }}

{# a custom route whose template knows which entry it shows #}
{{ craft.llmReady.webMcp({ entry: entry }) }}
```

The tag outputs the same three pieces automatic injection adds — the origin trial meta tag when a token is set, the JSON configuration block, and the deferred script — as literal markup at the point of the call. It can sit anywhere in the document, works in templates rendered outside Craft's page pipeline, and renders nothing when the plugin or **Enable WebMCP Tools** is off, so one CP setting still turns the feature off everywhere. An entry passed in is checked against the same rules as the `.md` URL — enabled section, live, not `noindex` — so the tag can never expose more than a crawler could fetch. Leave **Auto-inject WebMCP** on and the page carries the bootstrap twice.

### Troubleshooting

**`document.modelContext` is undefined.** The browser doesn't have WebMCP
enabled. Check the browser version (Chrome 149+/Edge 150+), the origin trial
token (per-origin, and tokens expire), or enable
`chrome://flags#enable-webmcp-testing` for local testing.

**The API exists but the inspector extension shows nothing.** The extension
needs Chrome 150+ and the testing flag. If the page's `llm-ready-webmcp`
JSON island is present and `getTools()` in the console lists the tools,
the plugin side is fine — reload the extension's panel.

**Nothing is injected at all — and the `<link rel="alternate">` discovery
tag is missing too.** The template has no `</head>`, `<body>`, or `</body>`.
Craft delivers everything a plugin registers (scripts, asset bundles, meta
tags) by inserting its `head()`, `beginBody()`, and `endBody()` hooks where
it finds those tags, and silently drops all of it when they are missing —
a bare fragment, or a scaffolded starter template that was never wrapped in
a layout, fails this way. Wrap the template in a full document, or place
the bootstrap yourself with `{{ craft.llmReady.webMcp() }}`; the discovery
tag has no manual equivalent.

**The tools don't appear on a page.** The same rules as the discovery tag
apply: the section must be enabled for the current site, the entry must be
live, and a `noindex` entry registers no tools. Check the page source for the
`llm-ready-webmcp` JSON island — if it's absent, the server decided not to
register tools on this page; if present, the issue is browser-side.

**Strict CSP blocks the script.** Allow the plugin's asset bundle URL in
`script-src`. The plugin uses no inline scripts or `eval`.

**The agent ignores the tools.** Tool *registration* working (verify via
`getTools()` in the console) doesn't guarantee a given agent *uses* them —
agent behavior varies by product and prompt. This is the newest part of the
ecosystem; expect it to improve.
