<?php

declare(strict_types=1);

namespace johnfmorton\llmready\services;

use Craft;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\models\Site;
use johnfmorton\llmready\LlmReady;
use yii\base\Component;

/**
 * Generates /llms.txt content
 */
class LlmsTxtService extends Component
{
    /**
     * Generate the llms.txt content for a site
     */
    public function generate(Site $site): string
    {
        $settings = LlmReady::getInstance()->getSettings();
        $cacheKey = $this->getCacheKey($site);

        // Check cache
        if ($settings->cacheTtl > 0) {
            $cached = Craft::$app->getCache()->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        $markdownService = LlmReady::getInstance()->markdownService;
        $seoService = LlmReady::getInstance()->seoService;
        $lines = [];

        // H1: Site title (or site name)
        $siteName = $settings->llmsTxtTitle ?: $site->getName();
        $lines[] = "# {$siteName}";
        $lines[] = '';

        // Blockquote: Intro text
        $introLines = $this->resolveIntro($site, $settings->llmsTxtIntro);
        if ($introLines !== []) {
            foreach ($introLines as $introLine) {
                $lines[] = rtrim('> ' . $introLine);
            }
            $lines[] = '';
        }

        // Get all sections
        $sections = Craft::$app->getEntries()->getAllSections();

        foreach ($sections as $section) {
            // Check if section is enabled for this site
            if (!$markdownService->isSectionEnabled($section->id, $site->id)) {
                continue;
            }

            // Check if the section has URLs for this site
            $siteSettings = $section->getSiteSettings();
            if (!isset($siteSettings[$site->id]) || !$siteSettings[$site->id]->hasUrls) {
                continue;
            }

            // Get entries for this section
            $entries = Entry::find()
                ->section($section->handle)
                ->site($site)
                ->status('live')
                ->orderBy('postDate desc')
                ->limit(50)
                ->all();

            $entryLines = [];
            foreach ($entries as $entry) {
                if ($seoService->isNoindex($entry)) {
                    continue;
                }

                $line = $markdownService->formatEntryLink($entry);
                if ($line !== null) {
                    $entryLines[] = $line;
                }
            }

            // Only add section heading if there are entries to list
            if (!empty($entryLines)) {
                $lines[] = "## {$section->name}";
                $lines[] = '';
                array_push($lines, ...$entryLines);
                $lines[] = '';
            }
        }

        $result = implode("\n", $lines);

        // Cache
        if ($settings->cacheTtl > 0) {
            Craft::$app->getCache()->set($cacheKey, $result, $settings->cacheTtl);
        }

        return $result;
    }

    /**
     * Drop the cached llms.txt for every site. Called when an entry or a
     * global set is saved, since the Site Description template can read
     * either — and, through Twig, any site's content, so one site's save
     * can't be assumed to affect only that site's file.
     */
    public function invalidateCache(): void
    {
        $cache = Craft::$app->getCache();

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $cache->delete($this->getCacheKey($site));
        }
    }

    private function getCacheKey(Site $site): string
    {
        return "llmready:llmstxt:{$site->id}";
    }

    /**
     * Resolve the Site Description setting to the lines of the blockquote.
     *
     * Plain text is used as written, one blockquote line per line. A value
     * containing `{` is a Craft object template — the same `{{ ... }}` syntax
     * as Title Field and Author Override — rendered with the site as `site`
     * (and as Craft's usual `object`). Global sets are available by handle as
     * in any site template, so `{{ siteInfo.llmDescription }}` hands the text
     * to content editors through a global set field, outside project config
     * and per site. Rich-text output is reduced to plain text with paragraph
     * breaks preserved.
     *
     * A template that throws logs a warning and resolves to no blockquote, so
     * a typo in a setting can't take down `/llms.txt`. Templates come from
     * plugin settings, which need an admin (or the config file) to change —
     * the same trust boundary as Craft's own object templates.
     *
     * @return string[]
     */
    private function resolveIntro(Site $site, string $intro): array
    {
        if (str_contains($intro, '{')) {
            try {
                $intro = Craft::$app->getView()->renderObjectTemplate($intro, $site, ['site' => $site]);
            } catch (\Throwable $e) {
                Craft::warning("LLM Ready: Site Description template '{$intro}' failed for site {$site->id}: {$e->getMessage()}", __METHOD__);

                return [];
            }

            $intro = $this->htmlToText($intro);
        }

        return $this->toLines($intro);
    }

    /**
     * Reduce rendered (possibly rich-text) output to plain text, keeping a
     * newline where the HTML had a line or block break so a multi-paragraph
     * description still reads as one.
     */
    private function htmlToText(string $html): string
    {
        $text = (string) preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6]|blockquote|tr)>~i', "\n", $html);
        $text = strip_tags($text);

        return Html::decode($text);
    }

    /**
     * Split text into trimmed lines with whitespace collapsed, runs of blank
     * lines reduced to one (a paragraph break inside the blockquote), and no
     * leading or trailing blank lines.
     *
     * @return string[]
     */
    private function toLines(string $text): array
    {
        $lines = [];
        $previousBlank = true;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            $blank = $line === '';

            if ($blank && $previousBlank) {
                continue;
            }

            $lines[] = $line;
            $previousBlank = $blank;
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
