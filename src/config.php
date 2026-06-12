<?php

/**
 * LLM Ready plugin for Craft CMS
 *
 * @link      https://github.com/johnfmorton/craft-llm-ready
 * @copyright Copyright (c) John F Morton
 */

/**
 * LLM Ready config.php
 *
 * This file exists only as a template for the LLM Ready settings.
 * It does nothing on its own.
 *
 * Don't edit this file. Instead, copy it to `config/` as `llm-ready.php`
 * and make your changes there. Settings defined in `config/llm-ready.php`
 * will override settings configured in the control panel.
 *
 * This file can be a standard PHP array, or a multi-environment config
 * (see https://craftcms.com/docs/5.x/config/#multi-environment-configs)
 */

return [
    // -----------------------------------------------------------------------
    // General
    // -----------------------------------------------------------------------

    // Whether the plugin is globally enabled
    'enabled' => true,

    // -----------------------------------------------------------------------
    // Markdown detection
    // -----------------------------------------------------------------------

    // Whether to serve Markdown when the request includes an
    // `Accept: text/markdown` header
    'enableContentNegotiation' => true,

    // Whether to automatically serve Markdown to known AI crawlers
    // (GPTBot, ClaudeBot, PerplexityBot, etc.)
    'enableUserAgentDetection' => true,

    // Additional bot user-agent strings to detect, appended to the built-in
    // list. Example: ['MyCustomBot', 'InternalCrawler/1.0']
    'additionalBotUserAgents' => [],

    // Full replacement for the built-in bot user-agent list. When set (non-empty)
    // these are used INSTEAD of the defaults below; `additionalBotUserAgents` is
    // still appended and `excludeBotUserAgents` still applied on top.
    //
    // The built-in defaults (so you know what you're replacing) are, grouped:
    //   OpenAI:     'GPTBot', 'ChatGPT-User', 'OAI-SearchBot'
    //   Anthropic:  'ClaudeBot', 'Claude-SearchBot', 'Claude-User'
    //   Perplexity: 'PerplexityBot', 'Perplexity-User'
    //   Meta:       'Meta-ExternalAgent', 'Meta-ExternalFetcher', 'Meta-WebIndexer'
    //   Other:      'Amazonbot', 'Bytespider', 'CCBot', 'cohere-ai'
    // (Matched case-insensitively as substrings of the User-Agent header.)
    'botUserAgents' => [],

    // Bot user-agent strings to remove from the effective list. Lets you drop a
    // specific default without re-listing all the others via `botUserAgents`.
    // Example: ['Amazonbot', 'Bytespider']
    'excludeBotUserAgents' => [],

    // -----------------------------------------------------------------------
    // Content extraction (automatic HTML-to-Markdown conversion)
    // -----------------------------------------------------------------------

    // CSS selectors for extracting main content from HTML (comma-separated).
    // Used when no dedicated LLM template is configured for a section.
    'contentSelector' => 'main, article, [role="main"], .content, #content',

    // CSS selectors for nodes to strip out before conversion (comma-separated).
    // Use for decorative or repeated regions inside the main content area, so
    // they never reach the Markdown output. Example: '.carousel, [data-nosnippet]'
    'excludeSelector' => '',

    // -----------------------------------------------------------------------
    // Response headers & discovery
    // -----------------------------------------------------------------------

    // Whether to add an `X-Robots-Tag: noindex` header to Markdown responses
    // to prevent search engines from indexing them
    'noindexHeader' => true,

    // Whether to auto-inject a `<link rel="alternate" type="text/markdown">`
    // discovery tag into the <head> of HTML pages
    'autoInjectDiscoveryTag' => true,

    // Whether to also advertise the Markdown alternate via an HTTP `Link`
    // response header (RFC 8288), emitted on both GET and HEAD requests, e.g.
    // `Link: <https://example.com/blog/my-post.md>; rel="alternate"; type="text/markdown"`
    // Useful for crawlers that inspect headers without parsing HTML.
    'autoInjectLinkHeader' => true,

    // -----------------------------------------------------------------------
    // Front matter
    // -----------------------------------------------------------------------

    // Field handle/path used as the front-matter `title:` value. Leave empty to
    // use the entry's native title. Supports dot notation (e.g. `seo.title`),
    // `()` method-call syntax (e.g. `metaData.getTitle()`), Generated Field
    // handles, and the SEOmatic resolver `seomatic:title`.
    'titleField' => '',

    // Author name written to every entry's front matter — e.g. an editorial
    // team name instead of the individual editor's name. Leave empty to use
    // each entry's own author.
    'authorOverride' => '',

    // -----------------------------------------------------------------------
    // /llms.txt and listing pages
    // -----------------------------------------------------------------------

    // Introduction text for the `/llms.txt` file. Appears as a blockquote
    // below the site name. Helps LLMs understand what the site is about.
    // Example: 'SuperGeekery is a technical blog covering Craft CMS and web development.'
    'llmsTxtIntro' => '',

    // Field handle/path for entry descriptions in `/llms.txt` and listing pages.
    // Leave empty to auto-extract from the first text field. Beyond a plain
    // handle, this supports dot notation (e.g. `seo.seoDescription`), `()`
    // method-call syntax (e.g. `metaData.getMetaDescription()`), Generated Field
    // handles, and a native SEOmatic resolver via `seomatic:description` (also
    // `seomatic:og-description`, `seomatic:twitter-description`).
    // When explicitly set, the configured field is authoritative — if it
    // resolves to nothing, the description is omitted rather than auto-extracted.
    // See SEO-PLUGINS.md for SEOmatic / Ether SEO / SEOmate / SEO Fields recipes.
    'descriptionField' => '',

    // -----------------------------------------------------------------------
    // Caching
    // -----------------------------------------------------------------------

    // How long to cache Markdown output, in seconds. Set to 0 to disable caching.
    'cacheTtl' => 3600,

    // -----------------------------------------------------------------------
    // Analytics
    // -----------------------------------------------------------------------

    // Whether to log AI bot requests for the analytics dashboard
    'enableAnalytics' => false,

    // Number of days to retain analytics data before it is eligible for purging
    'analyticsRetentionDays' => 90,
];
