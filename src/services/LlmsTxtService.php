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

        // H1: Site Title setting, or the site's name
        $lines[] = '# ' . $this->resolveTitle($site, $settings->llmsTxtTitle);
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
     * global set is saved, since the Site Title and Site Description
     * templates can read either — and, through Twig, any site's content, so
     * one site's save can't be assumed to affect only that site's file.
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
     * Resolve the Site Title setting to the H1 text.
     *
     * Blank falls back to the site's name, which is per site, so a static
     * title replaces every site's name in a multi-site install while a
     * template (see {@see renderSetting()}) can read per-site content. The
     * result is collapsed to a single line, since a newline would end the
     * heading, and a template that renders to nothing or throws falls back to
     * the site's name too.
     */
    private function resolveTitle(Site $site, string $title): string
    {
        $title = $this->renderSetting($site, $title, 'Site Title');
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return $title !== '' ? $title : $site->getName();
    }

    /**
     * Resolve the Site Description setting to the lines of the blockquote.
     *
     * Plain text is used as written, one blockquote line per line; a template
     * (see {@see renderSetting()}) that renders to nothing or throws resolves
     * to no blockquote.
     *
     * @return string[]
     */
    private function resolveIntro(Site $site, string $intro): array
    {
        return $this->toLines($this->renderSetting($site, $intro, 'Site Description'));
    }

    /**
     * Render a `/llms.txt` text setting for a site.
     *
     * Plain text is returned as written. A value containing `{` is a Craft
     * object template — the same `{{ ... }}` syntax as Title Field and Author
     * Override — rendered with the site as `site` (and as Craft's usual
     * `object`). Global sets are available by handle as in any site template,
     * so `{{ siteInfo.llmDescription }}` hands the text to content editors
     * through a global set field, outside project config and per site.
     * Rich-text output is reduced to plain text with paragraph breaks
     * preserved.
     *
     * A template that throws logs a warning and renders to an empty string,
     * so a typo in a setting can't take down `/llms.txt`. Templates come from
     * plugin settings, which need an admin (or the config file) to change —
     * the same trust boundary as Craft's own object templates.
     */
    private function renderSetting(Site $site, string $value, string $label): string
    {
        if (!str_contains($value, '{')) {
            return $value;
        }

        try {
            $rendered = Craft::$app->getView()->renderObjectTemplate($value, $site, ['site' => $site]);
        } catch (\Throwable $e) {
            Craft::warning("LLM Ready: {$label} template '{$value}' failed for site {$site->id}: {$e->getMessage()}", __METHOD__);

            return '';
        }

        return $this->htmlToText($rendered);
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
