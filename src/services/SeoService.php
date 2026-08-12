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
        try {
            $meta = $this->previewSeomaticMeta($entry);
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
     * Resolve an entry's SEOmatic meta variables without disturbing the meta
     * SEOmatic renders for the page itself.
     *
     * SEOmatic's `previewMetaContainers()` is destructive: it replaces the
     * `MetaContainers` service state with containers built for the previewed
     * entry — deliberately skipping the `DynamicMeta` pass (breadcrumbs,
     * hreflang, `sameAs`, the homepage name override) — and flips
     * `Seomatic::$previewingMetaContainers` / creates
     * `Seomatic::$seomaticVariable` as side effects. SEOmatic only builds the
     * real page's containers once, from its Twig extension's `getGlobals()`,
     * and only while both statics are still empty, so a preview that leaks
     * any of this state into the page render silently strips the page's
     * dynamic meta (#34).
     *
     * So: for the entry the current site request is rendering — the discovery
     * tag and content negotiation, which run just before the page template —
     * don't preview at all. Trigger the same normal, cached container load
     * `getGlobals()` would perform and read the resolved value from it. It is
     * the same value SEOmatic will emit in the page's own `robots` tag, and
     * it leaves SEOmatic exactly in its natural post-load state.
     *
     * Previews remain for foreign entries (`/llms.txt` loops, `.md` routes),
     * where no HTML page render follows. Even there the two statics are
     * saved and restored — the way SEOmatic's own `MetaBundle` does around
     * its internal preview calls — so that whatever renders afterwards (a
     * 404 template, for instance) still gets a normal SEOmatic load.
     *
     * Returns SEOmatic's parsed `MetaGlobalVars`, or null when SEOmatic isn't
     * installed/enabled or the entry has no URI. SEOmatic failures propagate:
     * each caller catches and logs with the context of its own lookup, and
     * fails open.
     */
    public function previewSeomaticMeta(Entry $entry): mixed
    {
        $seomaticClass = '\nystudio107\seomatic\Seomatic';

        if (!class_exists($seomaticClass)) {
            return null;
        }
        if (!Craft::$app->getPlugins()->isPluginEnabled('seomatic')) {
            return null;
        }

        $uri = $entry->uri;
        if (!is_string($uri) || $uri === '') {
            return null;
        }

        if ($this->isCurrentRequestEntry($entry)) {
            return $this->currentRequestSeomaticMeta($seomaticClass);
        }

        $previousPreviewing = $seomaticClass::$previewingMetaContainers;
        $previousVariable = $seomaticClass::$seomaticVariable;

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

            return $seomaticClass::$seomaticVariable?->meta;
        } finally {
            $seomaticClass::$previewingMetaContainers = $previousPreviewing;
            $seomaticClass::$seomaticVariable = $previousVariable;
        }
    }

    /**
     * Whether this entry is the one the current site request resolves to —
     * the case where SEOmatic's own meta for the request answers the lookup.
     */
    private function isCurrentRequestEntry(Entry $entry): bool
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof \craft\web\Request || !$request->getIsSiteRequest()) {
            return false;
        }

        try {
            $path = $request->getPathInfo();
        } catch (\Throwable) {
            return false;
        }

        return $entry->uri === ($path === '' ? Entry::HOMEPAGE_URI : $path)
            && (int) $entry->siteId === (int) Craft::$app->getSites()->getCurrentSite()->id;
    }

    /**
     * The meta SEOmatic resolves for the current request, loading it the way
     * SEOmatic's own Twig extension does if nothing has loaded it yet. The
     * guard mirrors `SeomaticTwigExtension::getGlobals()` exactly, so at
     * render time SEOmatic finds the load already done and does not repeat
     * it — the page renders with these very containers.
     *
     * @param class-string $seomaticClass
     */
    private function currentRequestSeomaticMeta(string $seomaticClass): mixed
    {
        if (!$seomaticClass::$seomaticVariable && !$seomaticClass::$previewingMetaContainers) {
            $variableClass = '\nystudio107\seomatic\variables\SeomaticVariable';
            if (!class_exists($variableClass)) {
                return null;
            }
            $seomaticClass::$seomaticVariable = new $variableClass();
            $seomaticClass::$plugin->metaContainers->loadMetaContainers(
                Craft::$app->getRequest()->getPathInfo(),
                null,
            );
        }

        return $seomaticClass::$seomaticVariable?->meta;
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
