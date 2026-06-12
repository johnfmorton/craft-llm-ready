<?php

declare(strict_types=1);

namespace johnfmorton\llmready\services;

use craft\web\Request;
use johnfmorton\llmready\LlmReady;
use yii\base\Component;

/**
 * Detects whether a request should be served as Markdown
 */
class DetectionService extends Component
{
    /**
     * Default AI bot user-agent strings, matched case-insensitively as
     * substrings of the request's User-Agent header.
     *
     * Override or trim this list per-install via config — see
     * `getEffectiveBotUserAgents()` and `config.php` for the
     * `botUserAgents` / `additionalBotUserAgents` / `excludeBotUserAgents`
     * settings.
     *
     * @var string[]
     */
    public const BOT_USER_AGENTS = [
        // OpenAI
        'GPTBot',           // training data collection
        'ChatGPT-User',     // user-requested fetch
        'OAI-SearchBot',    // search and citations

        // Anthropic
        'ClaudeBot',        // training data collection
        'Claude-SearchBot', // search and citations
        'Claude-User',      // user-requested fetch

        // Perplexity
        'PerplexityBot',    // answer index
        'Perplexity-User',  // user-requested fetch

        // Meta
        'Meta-ExternalAgent',   // AI training and indexing
        'Meta-ExternalFetcher', // user-requested fetch
        'Meta-WebIndexer',      // web discovery index

        // Other AI/LLM crawlers
        'Amazonbot',  // Amazon AI/Alexa discovery
        'Bytespider', // ByteDance AI data collection
        'CCBot',      // Common Crawl dataset collection
        'cohere-ai',  // Cohere AI data collection

        // Note: Google-Extended and Applebot-Extended are deliberately
        // omitted — they are robots.txt opt-out tokens, not request
        // User-Agents, so they never appear in a User-Agent header and
        // matching them here would be a no-op.
    ];

    /**
     * Check if the URL path ends with .md
     */
    public function isMarkdownUrlRequest(string $path): bool
    {
        return str_ends_with($path, '.md');
    }

    /**
     * Strip the .md suffix from a URL path
     */
    public function stripMarkdownSuffix(string $path): string
    {
        if (str_ends_with($path, '.md')) {
            return substr($path, 0, -3);
        }

        return $path;
    }

    /**
     * Check if the request uses Accept: text/markdown content negotiation
     */
    public function isContentNegotiation(Request $request): bool
    {
        $settings = LlmReady::getInstance()->getSettings();
        if (!$settings->enableContentNegotiation) {
            return false;
        }

        $acceptableTypes = $request->getAcceptableContentTypes();

        return isset($acceptableTypes['text/markdown']);
    }

    /**
     * Check if the request comes from a known AI bot user-agent
     */
    public function isAiBot(Request $request): bool
    {
        $settings = LlmReady::getInstance()->getSettings();
        if (!$settings->enableUserAgentDetection) {
            return false;
        }

        $userAgent = $request->getUserAgent() ?? '';
        if ($userAgent === '') {
            return false;
        }

        foreach ($this->getEffectiveBotUserAgents() as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The effective list of bot user-agent strings to detect, after applying
     * the config overrides:
     *
     * - `botUserAgents`, when non-empty, fully replaces the built-in
     *   {@see self::BOT_USER_AGENTS} defaults.
     * - `additionalBotUserAgents` are appended to the base list.
     * - `excludeBotUserAgents` are removed from the result (lets you drop a
     *   default without copying the whole list).
     *
     * Used by both detection ({@see self::isAiBot()}) and analytics bot
     * identification so the two never drift apart.
     *
     * @return string[]
     */
    public function getEffectiveBotUserAgents(): array
    {
        $settings = LlmReady::getInstance()->getSettings();

        $base = !empty($settings->botUserAgents)
            ? $settings->botUserAgents
            : self::BOT_USER_AGENTS;

        return array_values(array_diff(
            array_merge($base, $settings->additionalBotUserAgents),
            $settings->excludeBotUserAgents,
        ));
    }

    /**
     * Combined check: should this request be served as Markdown?
     * Note: .md URL suffix is handled separately via URL routing.
     * This method checks content negotiation and user-agent only.
     */
    public function shouldServeMarkdown(Request $request): bool
    {
        return $this->isContentNegotiation($request) || $this->isAiBot($request);
    }
}
