/**
 * LLM Ready — WebMCP prototype (Phase 0).
 *
 * Registers a read-only `get-page-content` tool for in-browser AI agents via
 * the WebMCP API, handing them the same Markdown rendering the entry's `.md`
 * URL serves. A silent no-op on browsers without the API.
 *
 * See WEBMCP-PLAN.md in the plugin repo for the design this implements.
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
    if (!config || typeof config.pageMarkdownUrl !== 'string') {
        return;
    }

    var tool = {
        name: 'get-page-content',
        title: 'Get page content',
        description: 'Get the current page\'s main content as clean Markdown ' +
            'with YAML front matter (title, date, author, canonical URL). ' +
            'Prefer this over reading the rendered HTML: it is the authored ' +
            'content without navigation, footers, or other page furniture.',
        inputSchema: {
            type: 'object',
            properties: {},
        },
        annotations: {
            readOnlyHint: true,
        },
        execute: function(input, options) {
            return fetch(config.pageMarkdownUrl, {
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
                            text: 'Could not load this page\'s Markdown (' +
                                error.message + '). It is served at ' +
                                config.pageMarkdownUrl + '.',
                        }],
                        isError: true,
                    };
                });
        },
    };

    // registerTool returns a promise per the spec, but the surface is
    // origin-trial-stage: guard both a sync throw and a rejection (e.g. the
    // permissions policy refusing the document) without breaking the page.
    try {
        Promise.resolve(context.registerTool(tool)).catch(function() {});
    } catch (e) {
        // Silent: the page must behave identically when registration fails.
    }
})();
