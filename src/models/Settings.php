<?php

declare(strict_types=1);

namespace johnfmorton\llmready\models;

use craft\base\Model;

/**
 * LLM Ready plugin settings
 */
class Settings extends Model
{
    /** @var bool Whether the plugin is globally enabled */
    public bool $enabled = true;

    /** @var bool Whether to add X-Robots-Tag: noindex to markdown responses */
    public bool $noindexHeader = true;

    /** @var bool Whether to auto-inject <link rel="alternate"> discovery tags */
    public bool $autoInjectDiscoveryTag = true;

    /** @var bool Whether to auto-inject an HTTP Link header pointing at the Markdown alternate */
    public bool $autoInjectLinkHeader = true;

    /** @var bool Whether to serve markdown via Accept: text/markdown header */
    public bool $enableContentNegotiation = true;

    /** @var bool Whether to serve markdown to known AI bot user agents */
    public bool $enableUserAgentDetection = true;

    /** @var string CSS selectors for smart content extraction (comma-separated) */
    public string $contentSelector = 'main, article, [role="main"], .content, #content';

    /** @var string CSS selectors for nodes to strip before extraction (comma-separated) */
    public string $excludeSelector = '';

    /** @var string[] Additional bot user-agent strings to detect (appended to the defaults) */
    public array $additionalBotUserAgents = [];

    /**
     * Full replacement for the built-in default bot user-agent list. When
     * non-empty, these are used instead of DetectionService::BOT_USER_AGENTS.
     * Intended to be set in config/llm-ready.php, not the control panel.
     *
     * @var string[]
     */
    public array $botUserAgents = [];

    /**
     * Bot user-agent strings to remove from the effective list — lets you drop
     * a specific default (e.g. one you don't want) without copying the whole
     * list. Intended to be set in config/llm-ready.php, not the control panel.
     *
     * @var string[]
     */
    public array $excludeBotUserAgents = [];

    /** @var string Site description for llms.txt header blockquote */
    public string $llmsTxtIntro = '';

    /** @var string Field handle for entry descriptions in llms.txt (empty = auto-extract) */
    public string $descriptionField = '';

    /** @var string Field handle/path used as the front-matter title (empty = entry.title) */
    public string $titleField = '';

    /** @var string Author name written to front matter for every entry (empty = use entry's author) */
    public string $authorOverride = '';

    /** @var int Cache TTL in seconds (0 = no caching) */
    public int $cacheTtl = 3600;

    /** @var bool Whether to enable analytics logging */
    public bool $enableAnalytics = false;

    /** @var int Number of days to retain analytics data */
    public int $analyticsRetentionDays = 90;

    public function rules(): array
    {
        return [
            [['enabled', 'noindexHeader', 'autoInjectDiscoveryTag', 'autoInjectLinkHeader', 'enableContentNegotiation', 'enableUserAgentDetection', 'enableAnalytics'], 'boolean'],
            [['contentSelector', 'excludeSelector', 'llmsTxtIntro', 'descriptionField', 'titleField', 'authorOverride'], 'string'],
            ['cacheTtl', 'integer', 'min' => 0],
            ['analyticsRetentionDays', 'integer', 'min' => 1],
            [['additionalBotUserAgents', 'botUserAgents', 'excludeBotUserAgents'], 'each', 'rule' => ['string']],
        ];
    }
}
