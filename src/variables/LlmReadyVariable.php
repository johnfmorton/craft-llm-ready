<?php

declare(strict_types=1);

namespace johnfmorton\llmready\variables;

use craft\elements\Entry;
use craft\helpers\Template;
use johnfmorton\llmready\LlmReady;
use Twig\Markup;

/**
 * Template helpers, available as `craft.llmReady` in Twig.
 */
class LlmReadyVariable
{
    /**
     * Render the WebMCP tool bootstrap for the current page.
     *
     * Outputs the same three pieces the automatic injection adds — the
     * origin-trial meta tag (when a token is set), the JSON configuration
     * block, and the deferred script tag — as literal markup at the point
     * of the call, so it works on any render path and in any position of
     * the document. Renders nothing when the plugin or **Enable WebMCP
     * Tools** is off, or when no tool applies to the page.
     *
     * Pass `entry` for a page whose URL does not resolve to an entry (a
     * custom route, say) but whose template knows which entry it shows.
     * The entry is subject to the same visibility rules as the `.md` URL:
     * enabled section, live, not `noindex`.
     *
     * ```twig
     * {{ craft.llmReady.webMcp() }}
     * {{ craft.llmReady.webMcp({ entry: entry }) }}
     * ```
     *
     * Turn off **Auto-inject WebMCP** when placing the tag yourself, or
     * the page carries the bootstrap twice.
     *
     * @param array<string, mixed> $options Accepts `entry` (an Entry).
     */
    public function webMcp(array $options = []): Markup
    {
        $entry = $options['entry'] ?? null;
        if ($entry !== null && !$entry instanceof Entry) {
            $entry = null;
        }

        return Template::raw(LlmReady::getInstance()->renderWebMcpHtml($entry));
    }
}
