# WebMCP Phase 0 — dev preview demo script

A short **dev-preview** video showing the Phase 0 spike working: the WebMCP
adapter plus the single hardcoded `get-page-content` tool, running on a test
site behind Chrome's flag. Target length: **2.5–3 minutes.**

This is deliberately not a launch video. Phase 0 has no settings UI, no
origin trial token support, and one tool — the video's honesty about that is
what makes the roadmap close credible. The full before/after launch script
([WEBMCP-DEMO-SCRIPT.md](WEBMCP-DEMO-SCRIPT.md)) waits for the Phase 2
feature set.

---

## Prep checklist

- [ ] Test Craft site with the Phase 0 prototype build of LLM Ready
      installed; a blog entry with enough real content that a specific-detail
      question has a findable answer.
- [ ] Chrome 149+ with `about:flags#enable-webmcp-testing` enabled and an
      agent surface available (or ChatGPT Desktop pointed at the test site).
- [ ] Console snippet on the clipboard: `await document.modelContext.getTools()`
- [ ] One rehearsed question the agent can only answer from deep in the
      entry's content. The same question gets asked before and after — keep
      it identical.
- [ ] DevTools docked right, ~125% zoom, agent sidebar visible.

---

## Cold open (0:00–0:20)

**SCREEN:** The test site's article page in Chrome, agent sidebar open.

> **NARRATION:** Browsers are starting to ship AI agents that work on the
> page you're looking at — and there's a new web standard, WebMCP, that
> lets a site hand those agents real tools instead of making them scrape.
> I've been prototyping WebMCP support for LLM Ready, my Craft CMS plugin.
> This is an early look at the first working piece — and where it goes
> from here.

---

## Before — no tools, so the agent scrapes (0:20–1:10)

**SCREEN:** DevTools console on the article page. Paste
`await document.modelContext.getTools()` → empty list.

> **NARRATION:** Here's this page as every site looks to an agent today.
> I ask the page what tools it offers — and it offers none.

**SCREEN:** Close DevTools. Ask the rehearsed question in the agent
sidebar. Let its page-reading visibly run; if it drags, jump-cut with a
timer overlay.

> **NARRATION:** So when I ask about this article, the agent falls back to
> reading the rendered page — navigation, footer, cookie notice and all —
> and digging the answer out of my design. It gets there, usually. But
> LLM Ready already renders a clean Markdown version of this exact page
> for crawlers. The agent sitting right here just has no way to know that.

**SCREEN:** Briefly show the same URL with `.md` appended — the clean
Markdown — then go back.

> **NARRATION:** That's the gap this prototype closes.

---

## The prototype — one tool, registered by the page (1:10–2:10)

**SCREEN:** Same article on the prototype build. DevTools console, same
snippet — this time it returns one tool. Expand it: `name:
"get-page-content"`, the `description`, the `inputSchema`.

> **NARRATION:** Same page, now running the prototype build of the plugin.
> One thing is different: the page registers a tool — get-page-content —
> through the browser's new WebMCP API, with a description and a typed
> schema. That's the page introducing itself to the agent in the agent's
> own language. Note what it took on the site side: nothing. No template
> changes — the plugin injects it, exactly like it already injects the
> Markdown discovery tag.

**SCREEN:** Close DevTools. Ask the *same* question. Highlight the
`get-page-content` tool call if the agent surface shows it, then the
answer.

> **NARRATION:** Same question as before. This time the agent calls the
> tool, and what comes back is that clean Markdown you saw — the version
> I authored, no scraping, no guessing. Faster, cheaper, and grounded in
> the content instead of the layout. Under the hood the tool is tiny: it
> hands the agent the same `.md` rendering LLM Ready has served crawlers
> all along. The new part is the delivery — in the page, at the moment
> the visitor and their agent are actually there.

---

## Honesty beat — what this is and isn't (2:10–2:30)

**SCREEN:** Slide or terminal with three lines: *Prototype · Chrome flag ·
Read-only.*

> **NARRATION:** To be clear about what you're seeing: this is a
> prototype, not a release. It runs behind a Chrome flag, there's no
> settings screen yet, and it's one tool. It's also read-only by design —
> nothing here can change the site or act for the visitor, and it only
> exposes content the site's public Markdown URLs already serve.

---

## Roadmap close — Phases 1 and 2 (2:30–3:00)

**SCREEN:** Simple roadmap card, three rows appearing as narrated:
*Phase 1 — ships in the plugin* / *Phase 2 — site-wide tools* /
*Your ideas*.

> **NARRATION:** Here's where it goes. Phase one is the shippable version:
> a plugin setting to turn it on, origin-trial token support so it works
> for real visitors in Chrome and Edge, and a second tool that hands the
> agent the site's llms.txt index. Phase two is the one I'm most excited
> about — a search-entries tool backed by a live query against Craft, so
> the agent can find content anywhere on the site, plus tool-call
> analytics in the plugin's existing dashboard.
>
> If you run Craft sites and there's a tool your site should be offering
> agents, now is exactly the time to tell me — link below. More when
> phase one ships.

**SCREEN:** End card: plugin name, repo URL, feedback link.

---

## Production notes

- **Keep the honesty beat.** A dev-preview that looks like a launch
  invites "where's the setting?" comments; naming the flag and the
  prototype status pre-empts them and makes the roadmap the story.
- **One question, asked twice.** The before/after hinges on the identical
  question; don't improvise a different phrasing in the after-take.
- **Agents are non-deterministic** — re-record the after-beat until the
  tool call is visible in the agent UI; if the surface hides tool calls,
  hold the console's `getTools()` result on screen longer as the proof
  instead.
- **Spike findings feed the plan.** Anything the recording surfaces about
  Chrome's actual API behavior belongs in WEBMCP-PLAN.md's Phase 0
  findings note, not just in the video.
- **Cutdown:** the console empty→populated contrast plus the after-answer
  makes a ~45-second short; the roadmap card works as a pinned comment
  instead.
