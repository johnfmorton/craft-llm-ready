# WebMCP planning notes

Working documents for the plugin's WebMCP support, kept out of the repo root because they are for the maintainer, not for people installing the plugin. The user-facing documentation is the [WebMCP tools](../../DOCUMENTATION.md#webmcp-tools) section of `DOCUMENTATION.md`.

| File | What it is |
|------|------------|
| [plan.md](plan.md) | The hub: what WebMCP is, what the plugin exposes, architecture, security review points, phased roadmap, and the Phase 0/1 findings with the manual validation checklist the release is gated on |
| [phase2-search-plan.md](phase2-search-plan.md) | Design exploration for exposing a site's search as WebMCP tools (Phase 2) |
| [docs-draft.md](docs-draft.md) | Early draft of the user documentation, superseded by `DOCUMENTATION.md` |
| [faq.md](faq.md) | Questions site owners are likely to ask, with answers |
| [talking-points.md](talking-points.md) | How to talk about the feature |
| [demo-script-phase0.md](demo-script-phase0.md) | Script for the dev-preview video of the Phase 1 build |
| [demo-script.md](demo-script.md) | Script for the full launch video, which waits for Phase 2's search tool |

This folder is excluded from Composer dist archives via `.gitattributes`.
