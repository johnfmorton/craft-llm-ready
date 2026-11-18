<?php

declare(strict_types=1);

namespace johnfmorton\llmready\events;

use craft\elements\Entry;
use craft\models\Site;
use yii\base\Event;

/**
 * Raised before LLM Ready surfaces an entry: before its Markdown is served
 * (at its `.md` URL, or at its canonical URL to an AI bot or an
 * `Accept: text/markdown` request), before it is listed in `/llms.txt` or on
 * a listing page, and before its HTML page advertises a Markdown alternate.
 *
 * Set `$include` to `false` to exclude the entry. An excluded entry is
 * treated like one an SEO plugin marks `noindex`: its `.md` URL returns a
 * 404, it is left out of `/llms.txt` and listing pages, and its HTML page
 * advertises no alternate. Live preview is exempt.
 *
 * Handlers can only exclude. The event runs before the plugin's own `noindex`
 * check, which still applies to every entry a handler leaves included.
 *
 * It runs once per listed entry while `/llms.txt` and listing pages are
 * built, so keep handlers cheap: read a field on the entry rather than run a
 * query.
 *
 * ```php
 * use johnfmorton\llmready\events\IncludeEntryEvent;
 * use johnfmorton\llmready\services\MarkdownService;
 * use yii\base\Event;
 *
 * Event::on(
 *     MarkdownService::class,
 *     MarkdownService::EVENT_INCLUDE_ENTRY,
 *     function(IncludeEntryEvent $event) {
 *         if ($event->entry->section?->handle === 'pages' && !$event->entry->showToLlms) {
 *             $event->include = false;
 *         }
 *     }
 * );
 * ```
 */
class IncludeEntryEvent extends Event
{
    /** @var Entry The entry about to be served, listed or advertised */
    public Entry $entry;

    /** @var Site The site the entry is being surfaced on */
    public Site $site;

    /** @var bool Whether the entry is included; set to `false` to exclude it */
    public bool $include = true;
}
