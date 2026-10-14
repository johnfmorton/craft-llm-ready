<?php

declare(strict_types=1);

namespace johnfmorton\llmready\jobs;

use Craft;
use craft\helpers\App;
use craft\queue\BaseJob;
use johnfmorton\llmready\LlmReady;

/**
 * Rebuilds stale index files — `/llms.txt` and section listing pages — in the
 * background, so a request never has to (#46). Queued by
 * {@see \johnfmorton\llmready\services\IndexCacheService} when content
 * changes or a served copy outlives the cache TTL.
 */
class RegenerateIndexes extends BaseJob
{
    public function execute($queue): void
    {
        $plugin = LlmReady::getInstance();

        // Cleared before building, so a save made while this runs queues
        // another rebuild rather than being folded into this one too late.
        $plugin->indexCacheService->clearQueuedMarker();

        // `queue/listen` runs every job in one long-lived process: reload the
        // settings it read at boot, and forget robots lookups from an earlier
        // job, since editors may have changed either since.
        $plugin->indexCacheService->refreshPluginConfig();
        $plugin->seoService->reset();

        App::maxPowerCaptain();

        $plugin->indexCacheService->regenerateStale(function(int $done, int $total) use ($queue): void {
            $this->setProgress($queue, $done / max(1, $total));
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('llm-ready', 'Regenerating llms.txt and listing pages');
    }
}
