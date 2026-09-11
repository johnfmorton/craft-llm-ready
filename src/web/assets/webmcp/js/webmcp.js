/**
 * LLM Ready — WebMCP tools.
 *
 * Registers read-only tools for in-browser AI agents via the WebMCP API:
 * `get-page-content` (the current page's Markdown, from its `.md` URL) and
 * `get-site-overview` (the site index, from /llms.txt). A silent no-op on
 * browsers without the API.
 *
 * See planning/webmcp/plan.md in the plugin repo for the design this implements.
 */
(function() {
    'use strict';

    // webmcp-types 0.1.7 and the spec define document.modelContext; earlier
    // Chrome previews shipped navigator.modelContext. The API is in origin
    // trial and still moving, so accept either — and nothing else in this
    // file may touch the global directly.
    var context = document.modelContext || navigator.modelContext;
    if (!context || typeof context.registerTool !== 'function') {
        return;
    }

    var island = document.getElementById('llm-ready-webmcp');
    if (!island) {
        return;
    }

    var config;
    try {
        config = JSON.parse(island.textContent);
    } catch (e) {
        return;
    }
    if (!config) {
        return;
    }

    /**
     * Register a read-only tool whose result is the text served at `url`.
     */
    function registerFetchTool(name, title, description, url) {
        var tool = {
            name: name,
            title: title,
            description: description,
            inputSchema: {
                type: 'object',
                properties: {},
            },
            annotations: {
                readOnlyHint: true,
            },
            execute: function(input, options) {
                return fetch(url, {
                    signal: options && options.signal,
                    headers: { 'Accept': 'text/markdown' },
                })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }
                        return response.text();
                    })
                    .then(function(text) {
                        return { content: [{ type: 'text', text: text }] };
                    })
                    .catch(function(error) {
                        return {
                            content: [{
                                type: 'text',
                                text: 'Could not load ' + url + ' (' +
                                    error.message + ').',
                            }],
                            isError: true,
                        };
                    });
            },
        };

        // registerTool returns a promise per the spec, but the surface is
        // origin-trial-stage: guard both a sync throw and a rejection (e.g.
        // the permissions policy refusing the document) without breaking
        // the page.
        try {
            Promise.resolve(context.registerTool(tool)).catch(function() {});
        } catch (e) {
            // Silent: the page must behave identically when registration fails.
        }
    }

    if (typeof config.pageMarkdownUrl === 'string') {
        registerFetchTool(
            'get-page-content',
            'Get page content',
            'Get the current page\'s main content as clean Markdown with ' +
                'YAML front matter (title, date, author, canonical URL). ' +
                'Prefer this over reading the rendered HTML: it is the ' +
                'authored content without navigation, footers, or other ' +
                'page furniture.',
            config.pageMarkdownUrl,
        );
    }

    if (typeof config.llmsTxtUrl === 'string') {
        registerFetchTool(
            'get-site-overview',
            'Get site overview',
            'Get an index of this entire website as Markdown: what the site ' +
                'is about and its content sections, with a link to a ' +
                'Markdown version of every page. Use it to find content ' +
                'beyond the current page, then fetch a listed .md URL to ' +
                'read a specific page.',
            config.llmsTxtUrl,
        );
    }
})();
