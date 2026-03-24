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
    /** @var string[] Default AI bot user-agent strings */
    public const BOT_USER_AGENTS = [
        'GPTBot',
        'ChatGPT-User',
        'OAI-SearchBot',
        'ClaudeBot',
        'Claude-Web',
        'Amazonbot',
        'Bytespider',
        'CCBot',
        'Google-Extended',
        'FacebookBot',
        'PerplexityBot',
        'Applebot-Extended',
        'cohere-ai',
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

        $botAgents = array_merge(
            self::BOT_USER_AGENTS,
            $settings->additionalBotUserAgents,
        );

        foreach ($botAgents as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return true;
            }
        }

        return false;
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
