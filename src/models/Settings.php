<?php

declare(strict_types=1);

namespace johnfmorton\llmready\models;

use Craft;
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

    /** @var bool Whether to serve /llms.txt and /.well-known/llms.txt */
    public bool $enableLlmsTxt = true;

    /**
     * Whether to serve Markdown on the canonical URL to known AI bot
     * user-agents.
     *
     * Defaults to false: varying the canonical URL's response by User-Agent
     * cannot be made both cache-correct and cache-efficient. Declaring
     * `Vary: User-Agent` would be correct but has effectively unbounded
     * cardinality, so shared caches (Cloudflare among them) ignore it for
     * HTML — which is what made this a cache-poisoning vector. Crawlers are
     * served through the `.md` URL and the discovery tag/header instead,
     * each of which is its own cacheable URL.
     *
     * Safe to turn on for origin-only sites with no shared cache in front.
     *
     * @var bool
     */
    public bool $enableUserAgentDetection = false;

    /** @var string CSS selectors for smart content extraction (comma-separated) */
    public string $contentSelector = 'main, article, [role="main"], .content, #content';

    /** @var string CSS selectors for elements to remove before extraction (comma-separated) */
    public string $excludeSelector = '';

    /**
     * The elements removed during Markdown conversion when nothing else is
     * configured. A constant so the settings page can show it as the field's
     * placeholder and the converter can fall back to it.
     */
    public const DEFAULT_EXCLUDE_ELEMENTS = 'script, style, nav, footer, header, audio, video, iframe, form, svg';

    /**
     * HTML elements to remove from the extracted content during Markdown
     * conversion, together with everything inside them. Comma-separated tag
     * names (spaces also accepted, the converter's own format); an empty
     * string removes nothing. Passed to league/html-to-markdown as
     * `remove_nodes`, which wants them space-separated — see getExcludeElements().
     *
     * @var string
     */
    public string $excludeElements = self::DEFAULT_EXCLUDE_ELEMENTS;

    /**
     * Extra options for league/html-to-markdown's HtmlConverter, merged over
     * the plugin's own (`strip_tags`, `header_style`, `remove_nodes`) so a
     * key here wins — a `remove_nodes` key replaces $excludeElements. Intended to
     * be set in config/llm-ready.php, not the control panel.
     *
     * @see https://github.com/thephpleague/html-to-markdown#configuration-options
     * @var array<string, mixed>
     */
    public array $htmlConverterOptions = [];

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

    /** @var string Site title for llms.txt H1 heading (empty = site name) */
    public string $llmsTxtTitle = '';

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
            [['contentSelector', 'excludeSelector', 'excludeElements', 'llmsTxtTitle', 'llmsTxtIntro', 'descriptionField', 'titleField', 'authorOverride'], 'string'],
            [['excludeElements'], 'validateExcludeElements'],
            [['htmlConverterOptions'], 'validateHtmlConverterOptions'],
            ['cacheTtl', 'integer', 'min' => 0],
            ['analyticsRetentionDays', 'integer', 'min' => 1],
            [['additionalBotUserAgents', 'botUserAgents', 'excludeBotUserAgents'], 'each', 'rule' => ['string']],
        ];
    }

    /**
     * The Excluded Elements list as league/html-to-markdown's `remove_nodes`
     * option expects it: lower-case tag names separated by single spaces.
     */
    public function getExcludeElements(): string
    {
        return implode(' ', self::parseTagList($this->excludeElements));
    }

    /**
     * Split a list of tag names written with spaces and/or commas into
     * unique lower-case tag names, in the order given.
     *
     * @return string[]
     */
    public static function parseTagList(string $list): array
    {
        $tags = preg_split('/[\s,]+/', strtolower(trim($list)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($tags));
    }

    /**
     * Excluded Elements holds tag names only. Anything else — a class, an ID,
     * an attribute selector — belongs in Exclude Selector, and the message
     * says so.
     */
    public function validateExcludeElements(string $attribute): void
    {
        $invalid = array_filter(
            self::parseTagList($this->$attribute),
            fn(string $tag): bool => !preg_match('/^[a-z][a-z0-9-]*$/', $tag),
        );

        if ($invalid !== []) {
            $this->addError($attribute, Craft::t('llm-ready', 'Excluded Elements takes a comma-separated list of HTML tag names. Not a tag name: {tags}. Use Exclude Selector for classes, IDs and attributes.', [
                'tags' => implode(', ', $invalid),
            ]));
        }
    }

    /**
     * htmlConverterOptions (from config/llm-ready.php) must be keyed by
     * option name.
     */
    public function validateHtmlConverterOptions(string $attribute): void
    {
        foreach (array_keys($this->$attribute) as $key) {
            if (!is_string($key) || $key === '') {
                $this->addError($attribute, Craft::t('llm-ready', 'htmlConverterOptions must be an array keyed by HtmlConverter option name.'));
                return;
            }
        }
    }
}
