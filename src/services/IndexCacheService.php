<?php

declare(strict_types=1);

namespace johnfmorton\llmready\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\models\Section;
use craft\models\Site;
use johnfmorton\llmready\jobs\RegenerateIndexes;
use johnfmorton\llmready\LlmReady;
use yii\base\Component;

/**
 * Caches the index files — `/llms.txt` and section listing pages (`/blog.md`)
 * — so a request never has to build one when a copy exists (#46).
 *
 * An index lists many entries and, with SEOmatic installed, runs a full
 * meta-container preview for each, so on a large site building one can take
 * longer than a gateway will wait. An index is identified by its site and,
 * for a listing page, its section; `/llms.txt` has no section.
 *
 * - The last built copy is kept with the time it was built, and served as
 *   long as it exists. It is stale once it is older than the cache TTL or
 *   than the last change to content it lists ({@see markStale()}); serving a
 *   stale copy queues {@see RegenerateIndexes} to replace it.
 * - With no copy at all (first request, or after clearing caches), one
 *   request builds it under a lock. Requests that arrive meanwhile wait a
 *   few seconds for that copy and otherwise get null, which the controller
 *   turns into a 503 with `Retry-After`.
 * - `craft llm-ready/indexes/regenerate` builds every index up front, for a
 *   deploy script.
 *
 * A cache TTL of 0 keeps the old behavior: built on every request, unlocked.
 */
class IndexCacheService extends Component
{
    /** Seconds a request waits for another request's cold build. */
    private const COLD_WAIT = 5;

    /** Seconds the queued-job marker lives if the job never clears it. */
    private const QUEUED_TTL = 600;

    private const QUEUED_KEY = 'llmready:index:queued';

    /**
     * The content to serve for an index, or null when it has never been
     * built and another request is building it right now.
     */
    public function get(Site $site, ?Section $section = null): ?string
    {
        if (LlmReady::getInstance()->getSettings()->cacheTtl <= 0) {
            return $this->build($site, $section);
        }

        $cached = $this->readCache($site, $section);
        if ($cached !== null) {
            if ($this->isStale($cached, $section)) {
                $this->queueRegeneration();
            }

            return $cached['content'];
        }

        $mutex = Craft::$app->getMutex();
        $lock = $this->getLockName($site, $section);

        if (!$mutex->acquire($lock, self::COLD_WAIT)) {
            return $this->readCache($site, $section)['content'] ?? null;
        }

        try {
            // Whoever held the lock may have just stored a copy.
            $cached = $this->readCache($site, $section);
            if ($cached !== null) {
                return $cached['content'];
            }

            // A gateway timeout doesn't stop PHP, so the build still
            // completes and the next request is served from it.
            App::maxPowerCaptain();

            return $this->buildAndStore($site, $section);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Build an index and store it as the copy to serve. Returns null without
     * building when another process holds the build lock for the whole of
     * `$wait` seconds — that build will be stored instead.
     */
    public function regenerate(Site $site, ?Section $section = null, int $wait = 0): ?string
    {
        $mutex = Craft::$app->getMutex();
        $lock = $this->getLockName($site, $section);

        if (!$mutex->acquire($lock, $wait)) {
            return null;
        }

        try {
            return $this->buildAndStore($site, $section);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Rebuild every stored copy that is stale, plus any site's `/llms.txt`
     * that has no copy yet. Listing pages nobody has requested stay unbuilt,
     * so a site with many sections only keeps up the ones in use.
     *
     * @param callable(int $done, int $total): void|null $progress
     */
    public function regenerateStale(?callable $progress = null): void
    {
        $indexes = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            array_push($indexes, ...$this->getIndexes($site));
        }

        foreach ($indexes as $i => [$site, $section]) {
            if ($progress !== null) {
                $progress($i, count($indexes));
            }

            $cached = $this->readCache($site, $section);
            $wanted = $cached === null ? $section === null : $this->isStale($cached, $section);

            // A locked index is already being built by a request or the
            // console command; that build is stored instead.
            if ($wanted) {
                $this->regenerate($site, $section);
            }
        }
    }

    /**
     * Every index a site serves: its `/llms.txt` when that is enabled, then a
     * listing page for each enabled section with a listing URL.
     *
     * @return list<array{0: Site, 1: Section|null}>
     */
    public function getIndexes(Site $site): array
    {
        $plugin = LlmReady::getInstance();
        $indexes = [];

        if ($plugin->getSettings()->enableLlmsTxt) {
            $indexes[] = [$site, null];
        }

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($plugin->markdownService->isSectionEnabled($section->id, $site->id)
                && $plugin->markdownService->getListingBaseUri($section, $site) !== null
            ) {
                $indexes[] = [$site, $section];
            }
        }

        return $indexes;
    }

    /**
     * Mark indexes out of date and queue a rebuild: every site's `/llms.txt`,
     * and, given a section, that section's listing pages. The current copies
     * keep being served until the rebuild replaces them, so a burst of saves
     * costs one rebuild rather than a cold build per save.
     */
    public function markStale(?int $sectionId = null): void
    {
        $cache = Craft::$app->getCache();
        $now = microtime(true);

        $cache->set($this->getDirtyKey(null), $now, 0);
        if ($sectionId !== null) {
            $cache->set($this->getDirtyKey($sectionId), $now, 0);
        }

        $this->queueRegeneration();
    }

    /**
     * Push {@see RegenerateIndexes} unless one is already waiting. The job
     * clears the marker as it starts, so a change made while it runs queues
     * exactly one more.
     */
    public function queueRegeneration(): void
    {
        $settings = LlmReady::getInstance()->getSettings();
        if (!$settings->enabled || $settings->cacheTtl <= 0) {
            return;
        }

        $cache = Craft::$app->getCache();
        if (!$cache->add(self::QUEUED_KEY, true, self::QUEUED_TTL)) {
            return;
        }

        try {
            Queue::push(new RegenerateIndexes());
        } catch (\Throwable $e) {
            $cache->delete(self::QUEUED_KEY);
            Craft::warning("LLM Ready: couldn't queue index regeneration: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * Reload this plugin's settings and per-section settings from the
     * database.
     *
     * Craft's `queue/listen` runs jobs inside its own long-lived process,
     * where both were loaded once at boot — so without this, a worker started
     * before someone changed Entries per Section or switched a section off
     * would keep building with the old values until it was restarted. Only
     * this plugin's own paths are read; `config/llm-ready.php` overrides are
     * applied on top, as Craft does when it loads the plugin.
     */
    public function refreshPluginConfig(): void
    {
        $plugin = LlmReady::getInstance();

        $settings = $this->readStoredConfig("plugins.{$plugin->handle}.settings");
        $fileConfig = Craft::$app->getConfig()->getConfigFromFile($plugin->handle);
        $plugin->setSettings(array_merge($settings, is_array($fileConfig) ? $fileConfig : []));

        $plugin->markdownService->useSectionSettings($this->readStoredConfig(LlmReady::PROJECT_CONFIG_PATH));
    }

    /**
     * A project config subtree straight from the `projectconfig` table,
     * assembled the way Craft assembles its own copy.
     *
     * @return array<string, mixed>
     */
    private function readStoredConfig(string $path): array
    {
        $rows = (new Query())
            ->select(['path', 'value'])
            ->from(Table::PROJECTCONFIG)
            ->where(['like', 'path', "{$path}.%", false])
            ->orderBy('path')
            ->pairs();

        $data = [];
        $prefixLength = strlen($path) + 1;

        foreach ($rows as $rowPath => $value) {
            ArrayHelper::setValue($data, substr((string) $rowPath, $prefixLength), Json::decode(StringHelper::decdec((string) $value)));
        }

        return ProjectConfigHelper::cleanupConfig($data);
    }

    /**
     * Called by {@see RegenerateIndexes} as it starts.
     */
    public function clearQueuedMarker(): void
    {
        Craft::$app->getCache()->delete(self::QUEUED_KEY);
    }

    private function build(Site $site, ?Section $section): string
    {
        $plugin = LlmReady::getInstance();

        return $section === null
            ? $plugin->llmsTxtService->generate($site)
            : $plugin->markdownService->buildListingPage($section, $site);
    }

    private function buildAndStore(Site $site, ?Section $section): string
    {
        // Stamped before building, so a save that lands mid-build still
        // leaves this copy stale.
        $generatedAt = microtime(true);
        $content = $this->build($site, $section);

        // No expiry: the TTL decides when the copy is stale, not when it
        // disappears, so there is always something to serve.
        Craft::$app->getCache()->set($this->getCacheKey($site, $section), [
            'content' => $content,
            'generatedAt' => $generatedAt,
        ], 0);

        return $content;
    }

    /**
     * @return array{content: string, generatedAt: float}|null
     */
    private function readCache(Site $site, ?Section $section): ?array
    {
        $cached = Craft::$app->getCache()->get($this->getCacheKey($site, $section));

        if (!is_array($cached) || !is_string($cached['content'] ?? null) || !is_numeric($cached['generatedAt'] ?? null)) {
            return null;
        }

        return ['content' => $cached['content'], 'generatedAt' => (float) $cached['generatedAt']];
    }

    /**
     * `/llms.txt` lists every section, so any marked change makes it stale;
     * a listing page only goes stale for changes to its own section.
     *
     * @param array{content: string, generatedAt: float} $cached
     */
    private function isStale(array $cached, ?Section $section): bool
    {
        $ttl = LlmReady::getInstance()->getSettings()->cacheTtl;
        if ($cached['generatedAt'] + $ttl < microtime(true)) {
            return true;
        }

        $dirtyAt = Craft::$app->getCache()->get($this->getDirtyKey($section?->id));

        return is_numeric($dirtyAt) && $cached['generatedAt'] < (float) $dirtyAt;
    }

    private function getCacheKey(Site $site, ?Section $section): string
    {
        return $section === null
            ? "llmready:index:llmstxt:{$site->id}"
            : "llmready:index:listing:{$site->id}:{$section->id}";
    }

    private function getLockName(Site $site, ?Section $section): string
    {
        return 'llmready:build:' . ($section === null ? "llmstxt:{$site->id}" : "listing:{$site->id}:{$section->id}");
    }

    private function getDirtyKey(?int $sectionId): string
    {
        return $sectionId === null ? 'llmready:index:dirty' : "llmready:index:dirty:{$sectionId}";
    }
}
