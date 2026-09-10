# WebMCP dev preview — demo script (Phase 1 build)

A short **dev-preview** video showing the WebMCP feature as built: the
`get-page-content` and `get-site-overview` tools, settings UI, and origin
trial token support. Target length: **3–3.5 minutes.**

The arc: the agent first reads the *current* page the easy way — then
answers a question about content **that isn't on the page at all**. That
second beat is the eye-opener: the page hands the agent the whole site's
scope, not just its own content. Simple to stage, and it lands the point
that WebMCP tools can surface content beyond what the visitor is looking at.

This is a preview, not a launch video — the feature ships after manual
validation against the real origin trial (WEBMCP-PLAN.md §7). The full
launch script ([WEBMCP-DEMO-SCRIPT.md](WEBMCP-DEMO-SCRIPT.md)) waits for
Phase 2's `search-entries`.

---

## Prep checklist

- [ ] Test Craft site with this branch's build of LLM Ready; a blog with
      10+ entries across at least two sections, so the site-scope question
      has a real answer living on a different page.
- [ ] Chrome 149+ with `about:flags#enable-webmcp-testing` enabled and an
      agent surface available (or ChatGPT Desktop pointed at the test site).
- [ ] **Enable WebMCP Tools** turned on in the plugin settings.
- [ ] Console snippet on the clipboard: `await document.modelContext.getTools()`
- [ ] Two rehearsed questions:
      1. A **page question** answerable only from deep in the current entry.
      2. A **site question** whose answer lives in a *different* entry —
         e.g. "Does this site have anything about [topic covered
         elsewhere]?" Confirm the target entry's title/description in
         `/llms.txt` actually signals the topic, so the agent can find it
         from the index.
- [ ] DevTools docked right, ~125% zoom, agent sidebar visible.

---

## Cold open (0:00–0:20)

**SCREEN:** The test site's article page in Chrome, agent sidebar open.

> **NARRATION:** Browsers are starting to ship AI agents that work on the
> page you're looking at — and there's a new web standard, WebMCP, that
> lets a site hand those agents real tools instead of making them scrape.
> I've been building WebMCP support into LLM Ready, my Craft CMS plugin.
> Here's an early look — including the part I think is genuinely
> eye-opening.

---

## Before — no tools, one page, no context (0:20–1:15)

**SCREEN:** DevTools console on the article page (plugin setting off, or a
build without the feature). `await document.modelContext.getTools()` →
empty list.

> **NARRATION:** First, this page as every site looks to an agent today: I
> ask what tools it offers, and it offers none.

**SCREEN:** Ask the **page question** in the agent sidebar. Let the
page-reading visibly run; jump-cut dead air with a timer overlay.

> **NARRATION:** So the agent scrapes — navigation, footer, cookie notice
> and all — and digs the answer out of my design. Slow, token-hungry, but
> it usually gets there.

**SCREEN:** Ask the **site question**. Show the agent hedging or guessing
from the nav.

> **NARRATION:** But ask about the *rest* of the site and it's stuck. It
> can see one page. The answer exists — three clicks away — and the agent
> has no way to know that.

---

## The change — one setting (1:15–1:40)

**SCREEN:** Craft CP → Settings → Plugins → LLM Ready → the WebMCP
section. Flip **Enable WebMCP Tools** on. Hover the **Origin Trial Token**
field briefly.

> **NARRATION:** Here's the change: one setting in the plugin. There's
> also a field for the origin trial token that makes this work for real
> visitors while the API is in preview — for today I'm on the browser
> flag. No template changes, no JavaScript written.

---

## After, beat 1 — the page, the easy way (1:40–2:15)

**SCREEN:** Reload the article. Console: `getTools()` now lists
`get-page-content` and `get-site-overview`; expand one to show the
schema and the `readOnlyHint` annotation.

> **NARRATION:** Same page, reloaded. Now it offers two tools, each
> described in the agent's native language — and each flagged read-only,
> part of the standard's safety model.

**SCREEN:** Ask the same **page question**. Highlight the
`get-page-content` call if visible.

> **NARRATION:** The page question again: the agent calls get-page-content
> and receives the clean Markdown my plugin has always rendered for
> crawlers — the authored content, no scraping.

---

## After, beat 2 — the eye-opener (2:15–2:55)

**SCREEN:** Ask the same **site question**. Highlight the
`get-site-overview` call, then the agent citing/linking the *other*
entry. Click through to it.

> **NARRATION:** Now the question this page can't answer. Watch: the agent
> calls get-site-overview, gets the site's full index — every section,
> every page, each with a Markdown link — finds the article on the other
> side of the site, reads it, and hands my visitor the answer with a real
> link.
>
> That's the part worth sitting with. The page just gave the agent the
> *whole site's* scope — content that isn't on this page at all. The
> agent went from a reader stuck on one page to a user of the entire
> site, and the site decided exactly what it could see.

---

## Honesty + roadmap close (2:55–3:25)

**SCREEN:** Slide: *Read-only · Public content only · Off by default ·
Origin trial.* Then a roadmap card: *Next: search-entries + tool
analytics.*

> **NARRATION:** What you saw is read-only, exposes only what the site's
> public Markdown URLs already serve, and ships off by default — the API
> is still in origin trial, and for every browser without it this is a
> silent no-op. Next up: a real search-entries tool backed by a live
> query against Craft — index-scanning is good, search is better — plus
> tool-call analytics in the plugin's dashboard. If there's a tool your
> Craft site should be offering agents, tell me now — link below.

**SCREEN:** End card: plugin name, repo URL, feedback link.

---

## Production notes

- **Beat 2 is the video.** Everything else supports it. Re-record until
  the `get-site-overview` call and the cross-site answer with a working
  link are both on screen; if the agent surface hides tool calls, keep
  the console `getTools()` proof up longer instead.
- **Same two questions, asked twice.** The before/after hinges on
  identical phrasing — don't improvise in the after-takes.
- **Stage the site question honestly.** Pick a topic genuinely absent
  from the current page and genuinely present in another entry whose
  llms.txt line signals it. Don't pick one the agent could guess from
  the nav menu — that muddies the before.
- **Keep the honesty beat.** Naming the flag, the token, and origin-trial
  status pre-empts "where do I get this?" comments.
- **Spike findings feed the plan.** Anything the recording surfaces about
  Chrome's actual API or agent behavior belongs in WEBMCP-PLAN.md §7.
- **Cutdown:** beat 2 alone, bookended by the empty→populated
  `getTools()` contrast, makes a ~60-second short.
