# Working on LLM Ready

> **Asked to install or configure LLM Ready in a Craft site?** Follow [AI-INSTALL.md](AI-INSTALL.md), a step-by-step runbook for exactly that. The rest of this file is for working on the plugin's own source.

## What this is

A Craft CMS 5 plugin (PHP 8.2+, Craft 5.9.18+) that serves Markdown versions of entries to AI crawlers and LLMs. Everything lives in `src/` in the usual Craft layout: `LlmReady.php` wires events and routes, `services/MarkdownService.php` does the HTML-to-Markdown work, `models/Settings.php` and `templates/settings/index.twig` are the settings, `config.php` is the commented template users copy to `config/llm-ready.php`. Human documentation is `README.md` and `DOCUMENTATION.md`.

## Checking your work

- Code style: `composer run check-cs` (auto-fix with `composer run fix-cs`). Static analysis: `composer run phpstan`. Run both from this directory. When the plugin is loaded into a DDEV site as a Composer path repository, run them inside the container: `ddev exec -d /var/www/html/plugins/llm-ready composer run check-cs`.
- There is no PHP test suite. Verify behaviour against a real Craft install: `curl` an entry's `.md` URL, or write a throwaway script that bootstraps Craft and calls the service directly, then delete it. Never commit test scripts or `.playwright-mcp/` output.

## A change is not done until

- **CHANGELOG.md** has an entry under `## [Unreleased]` in [Keep a Changelog](https://keepachangelog.com/) form (Added / Changed / Fixed). Explain the why, not only the what; credit the reporter plainly and link the issue. Version bumps happen in a separate release commit, not here.
- A new or changed setting is reflected in **all** of: `src/models/Settings.php` (property and `rules()`), `src/templates/settings/index.twig` (field, using the existing `overridden` warning-and-disable pattern), `src/config.php`, the settings table in `DOCUMENTATION.md`, and the settings table in `AI-INSTALL.md`.
- Behaviour a site owner could trip over has a line in DOCUMENTATION.md's FAQ or Troubleshooting section.

## Conventions

- Global settings are stored in project config. A key set in `config/llm-ready.php` overrides the control panel, and the settings page flags and disables that field. Per-section settings (`llm-ready.sectionSettings`) are control-panel only.
- Saving plugin settings flushes Craft's data cache. Markdown output is cached for `cacheTtl` seconds and invalidated when an entry is saved.
- Prefer a Craft or Yii API to a hand-rolled equivalent (`registerLinkTag()`, `setAttributes()`, `Html::decode()`).
- Craft namespaces element ids inside the settings template, so any JavaScript there hooks on classes, not ids.
- Field help text: lists are comma-separated, one verb per operation ("remove", not a mix of strip/drop/exclude), and backticks render as code.
