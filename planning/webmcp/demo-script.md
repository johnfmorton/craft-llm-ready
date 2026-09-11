# WebMCP feature — demo video script

Before/after demo showing what WebMCP support adds to a site already running
LLM Ready. Target length: **4–5 minutes**. Companion to
[plan.md](plan.md).

> **Prerequisite:** this script targets the **Phase 2** feature set — Beat 3
> depends on the `search-entries` tool, which doesn't exist until the Phase 2
> JSON endpoints ship. For an early video of the Phase 0 spike, use
> [demo-script-phase0.md](demo-script-phase0.md) instead.

The structure is a fair fight: the "before" site already has LLM Ready doing
everything it does today, so the contrast isolates exactly what the new
feature adds — the difference between an agent *scraping* the page and an
agent *using tools the site handed it*.

---

## Prep checklist (before recording)

- [ ] A demo Craft site with real-looking content — a blog with 10+ entries
      and at least two sections works well. LLM Ready installed, current
      version, `.md` URLs and `/llms.txt` confirmed working.
- [ ] Chrome 150+ with `chrome://flags#enable-webmcp-testing` enabled and
      Google's WebMCP Model Context Tool Inspector extension installed — its
      Gemini mode is the on-screen agent, and its manual mode is the
      fallback for a deterministic tool call on camera. ChatGPT Desktop
      works as an alternative agent. (Mention the origin trial token in
      narration; don't spend screen time on the console signup.)
- [ ] Two browser profiles or a way to toggle the plugin setting quickly —
      the before/after cut works best when the site is otherwise identical.
- [ ] DevTools console snippet on a clipboard:
      `await document.modelContext.getTools()`
- [ ] A question the agent can only answer well from your content, e.g.
      "What does this article say about [specific detail deep in the post]?"
      and a search-shaped ask: "Does this site have anything about [topic
      covered in a different entry]?" Rehearse both — agent behavior varies
      run to run, so know what a good take looks like.
- [ ] Pick recording dimensions that keep the agent sidebar and the page
      both readable; DevTools docked right, zoomed to ~125%.

---

## Cold open — the hook (0:00–0:20)

**SCREEN:** The demo site's article page, Chrome, agent sidebar open.
No typing yet.

> **NARRATION:** There's a new visitor showing up on your website: the AI
> agent built into the browser. It arrives with your visitor, it acts on the
> page they're looking at — and by default, it experiences your site the
> hard way. I'm going to show you what that looks like, and then what
> happens when your site starts talking back.

**SCREEN:** Title card: *LLM Ready + WebMCP — before and after.*

---

## Context — what LLM Ready already does (0:20–0:50)

**SCREEN:** Quick montage, ~8 seconds each: append `.md` to the article URL
and show the clean Markdown; then load `/llms.txt`.

> **NARRATION:** Quick context. This site runs LLM Ready, so AI *crawlers*
> already get the good version of this content — append `.md` to any URL
> and there's the page as clean Markdown, front matter and all. There's a
> site index at `/llms.txt`. That's the crawl-time story, and nothing about
> it changes today.
>
> But the agent in the browser isn't a crawler. It doesn't fetch your URLs
> from outside — it works with the page in front of it. So let's see what
> it does with no help.

---

## BEFORE — the agent scrapes (0:50–1:50)

**SCREEN:** Back on the article's normal HTML page. Open DevTools console,
paste `await document.modelContext.getTools()`. It returns an empty list.

> **NARRATION:** First, proof of what the page offers the agent right now:
> nothing. No tools registered.

**SCREEN:** Close DevTools. In the agent sidebar, ask the rehearsed
page question. Let it visibly work; if the agent shows its
reading/scanning steps, linger on them.

> **NARRATION:** So when I ask about this article, the agent does what
> agents do without help — it reads the rendered page. All of it. The
> navigation, the header, the footer, the cookie notice, the sidebar of
> related posts. It's digging your content out of your design.
>
> It usually gets there. But it's slower than it needs to be, it burns
> tokens on your nav menu, and on a complex layout it can grab the wrong
> thing entirely.

**SCREEN:** Ask the search-shaped question ("does this site have anything
about …?"). Show the agent hedging, or guessing from the nav, or offering
to browse around.

> **NARRATION:** And when I ask about the *rest* of the site, it's stuck.
> It can see one page. It can guess from your menu, or wander around
> clicking links like it's 2009. Your site has the answer — the agent just
> has no way to ask for it.

---

## THE CHANGE — one setting (1:50–2:20)

**SCREEN:** Craft control panel → Settings → Plugins → LLM Ready. Scroll to
the WebMCP section. Flip **Enable WebMCP Tools** on. Save.

> **NARRATION:** Here's the after. One setting: Enable WebMCP Tools.
>
> WebMCP is the emerging web standard — Chrome and Edge are running it as
> an origin trial — that lets a page hand an agent real tools: named
> actions with typed inputs, instead of a pile of HTML. Writing those
> tools yourself means JavaScript, schemas, and endpoints. LLM Ready
> ships them, wired to the content model you already configured.
>
> On a live site you'd also paste in a free origin-trial token — one field,
> covered in the docs. That's the whole setup. No template changes.

---

## AFTER — the site talks back (2:20–4:00)

**Beat 1 — proof (2:20–2:40).**

**SCREEN:** Reload the article. DevTools console, same snippet. This time it
returns the tools; expand one to show `name`, `description`, `inputSchema`.

> **NARRATION:** Same page, reloaded. Now the page is offering tools:
> get-page-content, get-site-overview, search-entries. Each one has a
> description and a typed schema — this is the page introducing itself to
> the agent in the agent's native language.

**Beat 2 — the page question, again (2:40–3:20).**

**SCREEN:** Close DevTools. Ask the agent the *same* page question as
before. If the agent surface shows tool calls, zoom or highlight the
`get-page-content` call when it appears.

> **NARRATION:** Same question as before. Watch the difference — the agent
> calls get-page-content, and what it receives is the clean Markdown you
> saw earlier. The version you authored. No nav, no footer, no guessing.
> Faster answer, fewer tokens, and it's working from your content instead
> of your layout.

**Beat 3 — the site question, again (3:20–4:00).**

**SCREEN:** Ask the same search-shaped question as before. Highlight the
`search-entries` call, then the agent's answer linking to the other entry.
Click through to that entry.

> **NARRATION:** And the question the agent couldn't answer before? Now it
> calls search-entries — that's a live query against Craft, scoped to the
> sections you enabled. It finds the article on the other side of the
> site, and hands my visitor a real link. The agent went from *a reader
> stuck on one page* to *a user of the whole site* — and your site decided
> what it could see.

---

## Safety beat (4:00–4:25)

**SCREEN:** Back to the plugin settings page, or a simple slide with three
lines: *Read-only · Public content only · Off by default.*

> **NARRATION:** Three things worth saying clearly. Everything you just saw
> is read-only — no tool the plugin ships can change anything or act for
> the visitor. The tools expose only what your public Markdown URLs
> already expose — enabled sections, live entries, and noindex is
> respected, so private stays private. And it's off by default: for every
> visitor whose browser doesn't have WebMCP yet, that script does nothing,
> and your site is unchanged.

---

## Close (4:25–4:50)

**SCREEN:** The article page with the agent sidebar showing the finished
answer. Then an end card: plugin name, `github.com/johnfmorton/craft-llm-ready`,
and "Docs: WebMCP" pointing at the documentation.

> **NARRATION:** So that's the addition. LLM Ready has always made your
> site readable to the AI systems that fetch it. WebMCP support makes it
> usable by the agent that shows up *with* your visitor — same content,
> same rules, one more front door. It's in the current release, off by
> default, documented end to end. Links below — and if there's a tool your
> site should be offering agents that we don't ship yet, that's exactly
> the feedback I want.

---

## Production notes

- **The before/after must be honest.** Same site, same content, same
  question, the only variable is the setting. Don't pick a "before"
  question the agent fumbles for unrelated reasons — the scrape usually
  *works*, it's just worse. The story is "hard way vs. handed the answer,"
  not "broken vs. fixed."
- **Agents are non-deterministic.** Record the after-beats until you get a
  take where the tool calls are visible in the agent UI; that visible
  `search-entries` call is the single most persuasive frame in the video.
  If the surface hides tool calls, keep DevTools' console proof on screen
  longer instead.
- **Timing is aspirational.** If the agent is slow in the before-segment,
  let it be slow — that *is* the point — but jump-cut the dead air with a
  timer overlay ("0:34 elapsed") rather than sitting through it.
- **Chapters for YouTube:** Hook / What LLM Ready already does / Before:
  scraping / The setting / After: tools / Safety / Wrap.
- **Cutdown:** beats 1–3 of the AFTER section plus the hook make a
  self-contained ~90-second short: empty `getTools()` → flip setting →
  tools appear → search answer. Record with that edit in mind.
