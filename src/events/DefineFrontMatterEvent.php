<?php

declare(strict_types=1);

namespace johnfmorton\llmready\events;

use craft\elements\Entry;
use craft\models\Site;
use yii\base\Event;

/**
 * Raised while LLM Ready builds the YAML front matter for an entry's Markdown
 * response — after the plugin has resolved its own values (title, date,
 * author, canonical_url, section) and before they are written out as YAML.
 *
 * Handlers may add, change or remove keys in `$frontMatter`. Each value may be:
 *
 * - a string — written as a scalar (`author: "Jane Doe"`)
 * - a list of strings — written as a YAML sequence (`authors:` followed by
 *   one `- ` item per line)
 * - `null` or empty — the key is omitted
 *
 * Values are escaped for YAML by the plugin, so handlers pass plain text.
 * Keys must match `[A-Za-z_][A-Za-z0-9_-]*`; anything else is skipped with a
 * warning.
 *
 * ```php
 * use johnfmorton\llmready\events\DefineFrontMatterEvent;
 * use johnfmorton\llmready\services\MarkdownService;
 * use yii\base\Event;
 *
 * Event::on(
 *     MarkdownService::class,
 *     MarkdownService::EVENT_DEFINE_FRONT_MATTER,
 *     function(DefineFrontMatterEvent $event) {
 *         $event->frontMatter['authors'] = $event->entry->entryAuthors->collect()->pluck('title')->all();
 *         unset($event->frontMatter['author']);
 *     }
 * );
 * ```
 */
class DefineFrontMatterEvent extends Event
{
    /** @var Entry The entry whose Markdown response is being built */
    public Entry $entry;

    /** @var Site The site the response is being built for */
    public Site $site;

    /**
     * @var array<string, string|string[]|null> Front matter keys in output
     * order, pre-populated with the plugin's resolved values.
     */
    public array $frontMatter = [];
}
