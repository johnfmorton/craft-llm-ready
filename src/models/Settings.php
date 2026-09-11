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

    /**
     * Whether to inject the WebMCP tool script on site pages, registering
     * read-only tools (`get-page-content`, `get-site-overview`) for
     * in-browser AI agents.
     *
     * Off by default while the WebMCP API is in origin trial: real visitors
     * need the site to serve an origin trial token (see
     * $webMcpOriginTrialToken), and local testing needs Chrome 150+ with the
     * `chrome://flags#enable-webmcp-testing` flag. Browsers without the API are
     * unaffected either way — the script is a silent no-op there.
     *
     * @var bool
     */
    public bool $enableWebMcp = false;

    /**
     * Whether to inject the WebMCP bootstrap automatically on site pages
     * (when $enableWebMcp is on). Turn off to place it yourself with
     * `{{ craft.llmReady.webMcp() }}` — for custom routes, templates rendered
     * outside Craft's page pipeline, or full control over script placement.
     *
     * @var bool
     */
    public bool $autoInjectWebMcp = true;

    /**
     * Chrome/Edge Origin Trial token for the WebMCP API, injected as a
     * `<meta http-equiv="origin-trial">` tag on pages that carry the tool
     * script. Tokens are issued per origin at
     * https://developer.chrome.com/origintrials/, so a multi-site install
     * whose sites live on different domains needs one per site: in
     * config/llm-ready.php the value may be an array keyed by site handle
     * (`['default' => 'A0x…', 'fr' => 'A0y…']`); a site with no entry gets
     * no tag. The control panel field holds a single token for every site.
     * Leave empty for flag-based local testing.
     *
     * @var string|array<string, mixed>
     */
    public string|array $webMcpOriginTrialToken = '';

    /** @var int Number of days to retain analytics data */
    public int $analyticsRetentionDays = 90;

    public function rules(): array
    {
        return [
            [['enabled', 'noindexHeader', 'autoInjectDiscoveryTag', 'autoInjectLinkHeader', 'enableContentNegotiation', 'enableUserAgentDetection', 'enableAnalytics', 'enableWebMcp', 'autoInjectWebMcp'], 'boolean'],
            [['contentSelector', 'excludeSelector', 'llmsTxtTitle', 'llmsTxtIntro', 'descriptionField', 'titleField', 'authorOverride'], 'string'],
            [['webMcpOriginTrialToken'], 'validateOriginTrialToken'],
            ['cacheTtl', 'integer', 'min' => 0],
            ['analyticsRetentionDays', 'integer', 'min' => 1],
            [['additionalBotUserAgents', 'botUserAgents', 'excludeBotUserAgents'], 'each', 'rule' => ['string']],
        ];
    }
    /**
     * The origin trial token for a site: the configured string, or the
     * matching entry of a per-site array. Empty when none applies.
     *
     * @param string|null $siteHandle Defaults to the current site.
     */
    public function getWebMcpOriginTrialToken(?string $siteHandle = null): string
    {
        $token = $this->webMcpOriginTrialToken;
        if (is_string($token)) {
            return trim($token);
        }

        $siteHandle ??= Craft::$app->getSites()->getCurrentSite()->handle;
        $value = $token[$siteHandle] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * A token is a string, or (from config/llm-ready.php) an array of
     * strings keyed by site handle.
     */
    public function validateOriginTrialToken(string $attribute): void
    {
        $value = $this->$attribute;
        if (is_string($value)) {
            return;
        }

        foreach ($value as $handle => $token) {
            if (!is_string($handle) || !is_string($token)) {
                $this->addError($attribute, Craft::t('llm-ready', 'Per-site origin trial tokens must be an array of strings keyed by site handle.'));
                return;
            }
        }
    }
}
