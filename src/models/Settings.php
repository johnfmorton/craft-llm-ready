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

    /** @var bool Whether to serve markdown via Accept: text/markdown header */
    public bool $enableContentNegotiation = true;

    /** @var bool Whether to serve markdown to known AI bot user agents */
    public bool $enableUserAgentDetection = true;

    /** @var string CSS selectors for smart content extraction (comma-separated) */
    public string $contentSelector = 'main, article, [role="main"], .content, #content';

    /** @var string[] Additional bot user-agent strings to detect */
    public array $additionalBotUserAgents = [];

    /** @var string Site description for llms.txt header blockquote */
    public string $llmsTxtIntro = '';

    /** @var string Field handle for entry descriptions in llms.txt (empty = auto-extract) */
    public string $descriptionField = '';

    /** @var int Cache TTL in seconds (0 = no caching) */
    public int $cacheTtl = 3600;

    public function rules(): array
    {
        return [
            [['enabled', 'noindexHeader', 'autoInjectDiscoveryTag', 'enableContentNegotiation', 'enableUserAgentDetection'], 'boolean'],
            [['contentSelector', 'llmsTxtIntro', 'descriptionField'], 'string'],
            ['cacheTtl', 'integer', 'min' => 0],
            ['additionalBotUserAgents', 'each', 'rule' => ['string']],
        ];
    }
}
