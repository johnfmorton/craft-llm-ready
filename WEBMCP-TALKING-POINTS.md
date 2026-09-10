# WebMCP support — talking points

For discussing the feature with plugin users: Discord/Stack Exchange threads,
a blog post announcing the feature, CraftQuest/Craft CMS community calls.
Companion to [WEBMCP-PLAN.md](WEBMCP-PLAN.md).

---

## The one-liner

> LLM Ready made your site *readable* by AI crawlers. WebMCP support makes it
> *usable* by the AI agent in your visitor's browser — with the same
> zero-template-changes promise.

## The story in three beats

1. **A new kind of visitor is arriving.** Until now, AI met your site from
   the outside: crawlers fetching URLs. Browsers are now shipping agents that
   sit *inside* the visit — Chrome and Edge have WebMCP origin trials live,
   ChatGPT Desktop browses with it, Brave is experimenting. These agents act
   on the page your visitor is actually looking at.

2. **Without help, those agents scrape.** An in-browser agent's default move
   is reading your rendered HTML — nav, cookie banners, and all — and
   guessing. WebMCP is the emerging W3C standard that lets a page hand the
   agent structured tools instead: "here's this page's real content," "here's
   what this site contains," "here's search."

3. **LLM Ready already has everything those tools need.** The plugin already
   knows how to turn any entry into clean Markdown, index the site, and
   respect your SEO rules. WebMCP support is a new front door to that same
   engine: flip one setting and every enabled page offers `get-page-content`
   and `get-site-overview` to capable browsers. No JavaScript to write, no
   templates to touch.

## Why this fits LLM Ready (and isn't scope creep)

- Same content model, new audience. The crawler features serve agents that
  *fetch*; WebMCP serves agents that *accompany*. Both consume the identical
  Markdown pipeline, section settings, and noindex rules.
- The plugin's whole brand is "your content, machine-readable, zero config."
  This is the browser-native chapter of that promise.
- Search (`search-entries`, Phase 2) is the first capability crawling can't
  replicate: a live, structured query answered by Craft, not by whatever the
  agent memorized about your site.

## Honest framing (say this up front)

- **It's early.** WebMCP is in origin trial — Chrome 149+, Edge 150+, plus
  ChatGPT Desktop and experimental Brave support. Firefox and Safari haven't
  shipped. That's why the setting defaults to **off** and why enabling it is
  risk-free: browsers without the API never see anything.
- **The spec is still moving.** All API access goes through one small
  adapter, so when the standard shifts, the plugin updates — your site
  doesn't care.
- **Trying it costs a setting and a token.** The origin trial token is a
  paste-into-settings step; local testing needs only a browser flag. If the
  ecosystem fizzles, you turn the setting off and have lost nothing.

## Safety message (the part cautious users need to hear)

- Everything is **read-only**. The plugin ships no tool that can change
  content, submit a form, or spend money.
- Tools expose only what your public `.md` URLs already expose: enabled
  sections, live entries, `noindex` respected. If it isn't public, no tool
  serves it — and tool endpoints always render the anonymous view, so even a
  logged-in editor's agent can't see drafts.
- The injected script is ~2 KB, no inline JS (strict-CSP friendly), and a
  silent no-op for every visitor without a WebMCP browser. Full-page caches
  (Blitz, Cloudflare) are unaffected — nothing varies by visitor.

## Differentiation

- **vs. doing it yourself:** hand-writing WebMCP tools means writing tool
  descriptors, feature detection across a moving spec, content endpoints,
  and visibility rules. The plugin ships all of it, wired to content you've
  already modeled.
- **vs. llms.txt/`.md` alone:** those serve the crawl-time world and remain
  the workhorses. WebMCP adds the visit-time world. You want both; they share
  one configuration.
- **first-mover angle:** no other Craft plugin occupies this spot yet.
  Agencies get a concrete, demoable answer to "what's our AI story?" —
  open the site in Chrome, ask the agent a question, watch it use the
  site's own tools.

## For the "why should I care now?" skeptic

- Cheap option on the future: one setting today; when WebMCP-capable browsers
  reach your audience, your site is already fluent.
- The same argument that got them to install LLM Ready: agent traffic is
  growing whether or not you prepare for it; preparation is nearly free here.
- If they're still unconvinced: fine — the feature is off by default and the
  rest of the plugin doesn't change. No pressure, no lock-in.

## Roadmap teaser (set expectations, invite feedback)

- **Now (Phase 1):** page content + site overview tools.
- **Next (Phase 2):** live search and listing tools, analytics for tool calls
  in the existing dashboard.
- **Later (Phase 3):** an event so *your* modules register site-specific
  tools (store hours, product filters) through the plugin's plumbing — with
  clear guidance that mutating tools carry real responsibility.
- Ask users: which tools would their sites actually want? This is the best
  moment to shape the extensibility layer.

## Q&A landmines to be ready for

- *"Is this just hype?"* — Maybe partly; that's why it's opt-in and read-only.
  The bet is small and the downside is a toggle.
- *"Does this let AI change my site?"* — No. Read-only, full stop. Developer
  extensibility later can enable actions, but that's the developer's explicit
  choice and code.
- *"Prompt injection?"* — Tool output is your published content, exactly what
  crawlers already read from `.md`. The plugin adds no instructions to it.
  Agents deciding how to treat page content is a browser/agent-side problem
  the standard bodies are actively working; the plugin doesn't enlarge it.
- *"What about headless Craft?"* — Injection happens at Twig render, so pure
  headless sites are out of scope; documented as such.
- *"Google/OpenAI will just scrape anyway."* — Yes, and the crawler features
  cover that path. WebMCP is about making the in-page experience better than
  scraping — for the visitor's benefit, not the model vendor's.
