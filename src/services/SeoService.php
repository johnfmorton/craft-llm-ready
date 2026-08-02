<?php

declare(strict_types=1);

namespace johnfmorton\llmready\services;

use Craft;
use craft\elements\Entry;
use yii\base\Component;

/**
 * Reads `noindex` intent from third-party SEO plugins.
 *
 * `noindex` is an explicit "don't surface this URL" signal, so LLM Ready
 * honours it the same way a search engine would: the entry is dropped from
 * `/llms.txt` and listing pages, its `.md` URL 404s, and content negotiation
 * on the canonical URL falls through to the normal HTML response.
 *
 * Supported: SEOmatic (`nystudio107/craft-seomatic`) and Ether SEO
 * (`ether/seo`). Other SEO plugins have no per-entry robots concept that maps
 * cleanly onto this, so they are not consulted.
 *
 * Every lookup fails open — if a plugin's API throws, changes shape, or
 * returns something unexpected, the entry is treated as indexable. Hiding
 * content because of an integration error is worse than missing a noindex.
 */
class SeoService extends Component
{
    /**
     * Robots directives that mean "do not index this URL". `none` is
     * shorthand for `noindex, nofollow`.
     */
    private const NOINDEX_DIRECTIVES = ['noindex', 'none'];

    /**
     * Per-request memo, keyed "entryId:siteId". `/llms.txt` and listing pages
     * can ask about the same entry more than once per request, and the
     * SEOmatic lookup is expensive enough to be worth not repeating.
     *
     * @var array<string, bool>
     */
    private array $_noindex = [];

    /**
     * Whether an SEO plugin marks this entry as `noindex`.
     */
    public function isNoindex(Entry $entry): bool
    {
        if ($entry->id === null) {
            return false;
        }

        $key = "{$entry->id}:{$entry->siteId}";

        return $this->_noindex[$key] ??= $this->resolveNoindex($entry);
    }

    private function resolveNoindex(Entry $entry): bool
    {
        // Ether SEO first: it is a plain field read on an already-loaded
        // element, where the SEOmatic path rebuilds meta containers.
        return $this->etherSeoIsNoindex($entry) || $this->seomaticIsNoindex($entry);
    }

    /**
     * Ether SEO stores robots as an array of directives on the field value's
     * `advanced` property.
     */
    private function etherSeoIsNoindex(Entry $entry): bool
    {
        $seoDataClass = '\ether\seo\models\data\SeoData';

        if (!class_exists($seoDataClass)) {
            return false;
        }
        if (!Craft::$app->getPlugins()->isPluginEnabled('seo')) {
            return false;
        }

        try {
            $fieldLayout = $entry->getFieldLayout();
            if ($fieldLayout === null) {
                return false;
            }

            // The SEO field's handle is project-defined, so find it by type
            // rather than making the site owner configure a handle.
            foreach ($fieldLayout->getCustomFields() as $field) {
                $value = $entry->getFieldValue($field->handle);
                if (!$value instanceof $seoDataClass) {
                    continue;
                }

                $robots = $value->advanced['robots'] ?? null;
                if (is_array($robots) && $this->containsNoindex($robots)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Craft::warning(
                "LLM Ready: Ether SEO robots lookup failed for entry {$entry->id}: {$e->getMessage()}",
                __METHOD__,
            );
        }

        return false;
    }

    /**
     * SEOmatic resolves robots through its meta bundle chain — per-entry
     * override, then section/entry-type default, then the global default — so
     * this deliberately runs the full resolver rather than reading a field.
     * An entry that inherits `noindex` from its section is just as noindex as
     * one that sets it directly.
     */
    private function seomaticIsNoindex(Entry $entry): bool
    {
        $seomaticClass = '\nystudio107\seomatic\Seomatic';

        if (!class_exists($seomaticClass)) {
            return false;
        }
        if (!Craft::$app->getPlugins()->isPluginEnabled('seomatic')) {
            return false;
        }

        $uri = $entry->uri;
        if (!is_string($uri) || $uri === '') {
            return false;
        }

        try {
            // Drop the early-return guard inside previewMetaContainers so we
            // get fresh resolution even when SEOmatic has already run for
            // the outer /llms.txt or .md request.
            $seomaticClass::$previewingMetaContainers = false;

            $plugin = $seomaticClass::$plugin;
            $plugin->metaContainers->previewMetaContainers(
                $uri,
                (int) $entry->siteId,
                true,
                true,
                $entry,
            );
            $plugin->metaContainers->parseGlobalVars();

            $meta = $seomaticClass::$seomaticVariable?->meta;
            if ($meta === null) {
                return false;
            }

            $robots = $meta->parsedValue('robots');
            if (!is_string($robots) || $robots === '') {
                return false;
            }

            return $this->containsNoindex(explode(',', $robots));
        } catch (\Throwable $e) {
            Craft::warning(
                "LLM Ready: SEOmatic robots lookup failed for entry {$entry->id}: {$e->getMessage()}",
                __METHOD__,
            );

            return false;
        }
    }

    /**
     * @param array<array-key, mixed> $directives
     */
    private function containsNoindex(array $directives): bool
    {
        foreach ($directives as $directive) {
            if (!is_string($directive)) {
                continue;
            }

            if (in_array(strtolower(trim($directive)), self::NOINDEX_DIRECTIVES, true)) {
                return true;
            }
        }

        return false;
    }
}
