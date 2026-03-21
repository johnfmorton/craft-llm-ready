<?php

declare(strict_types=1);

namespace johnfmorton\llmready\services;

use Craft;
use craft\elements\Entry;
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
        $cacheKey = "llmready:llmstxt:{$site->id}";

        // Check cache
        if ($settings->cacheTtl > 0) {
            $cached = Craft::$app->getCache()->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        $markdownService = LlmReady::getInstance()->markdownService;
        $lines = [];

        // H1: Site name
        $siteName = $site->getName();
        $lines[] = "# {$siteName}";
        $lines[] = '';

        // Blockquote: Intro text
        if ($settings->llmsTxtIntro) {
            $introLines = explode("\n", $settings->llmsTxtIntro);
            foreach ($introLines as $introLine) {
                $lines[] = '> ' . trim($introLine);
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
}
