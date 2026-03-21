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
    // Whether the plugin is globally enabled
    'enabled' => true,

    // Whether to serve Markdown when the request includes an
    // `Accept: text/markdown` header
    'enableContentNegotiation' => true,

    // Whether to automatically serve Markdown to known AI crawlers
    // (GPTBot, ClaudeBot, PerplexityBot, etc.)
    'enableUserAgentDetection' => true,

    // Additional bot user-agent strings to detect beyond the built-in list.
    // Example: ['MyCustomBot', 'InternalCrawler/1.0']
    'additionalBotUserAgents' => [],

    // CSS selectors for extracting main content from HTML (comma-separated).
    // Used when no dedicated LLM template is configured for a section.
    'contentSelector' => 'main, article, [role="main"], .content, #content',

    // Whether to add an `X-Robots-Tag: noindex` header to Markdown responses
    // to prevent search engines from indexing them
    'noindexHeader' => true,

    // Whether to auto-inject `<link rel="alternate" type="text/markdown">`
    // discovery tags into HTML pages
    'autoInjectDiscoveryTag' => true,

    // How long to cache Markdown output, in seconds. Set to 0 to disable caching.
    'cacheTtl' => 3600,

    // Introduction text for the `/llms.txt` file. Appears as a blockquote
    // below the site name. Helps LLMs understand what the site is about.
    // Example: 'SuperGeekery is a technical blog covering Craft CMS and web development.'
    'llmsTxtIntro' => '',

    // Field handle to use for entry descriptions in `/llms.txt` and listing
    // pages. Leave empty to auto-extract from the first text field.
    // Example: 'summary', 'excerpt', 'description'
    'descriptionField' => '',
];
