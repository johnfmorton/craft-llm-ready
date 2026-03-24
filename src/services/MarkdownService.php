<?php

declare(strict_types=1);

namespace johnfmorton\llmready\services;

use Craft;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\Section;
use craft\models\Site;
use johnfmorton\llmready\LlmReady;
use League\HTMLToMarkdown\HtmlConverter;
use yii\base\Component;
use yii\helpers\Html;

/**
 * Handles Markdown conversion and rendering
 */
class MarkdownService extends Component
{
    private ?HtmlConverter $_converter = null;

    /**
     * Render Markdown for an entry
     */
    public function renderMarkdown(Entry $entry, Site $site): string
    {
        $settings = LlmReady::getInstance()->getSettings();
        $cacheKey = $this->getCacheKey($entry, $site);

        // Check cache
        if ($settings->cacheTtl > 0) {
            $cached = Craft::$app->getCache()->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Check for dedicated LLM template
        $sectionConfig = $this->getSectionConfig($entry->sectionId, $site->id);
        $llmTemplate = $sectionConfig['llmTemplate'] ?? null;

        if ($llmTemplate) {
            $markdown = $this->renderLlmTemplate($entry, $llmTemplate);
        } else {
            $markdown = $this->convertEntryHtmlToMarkdown($entry, $site);
        }

        // Prepend front matter
        $frontMatter = $this->buildFrontMatter($entry, $site);
        $result = $frontMatter . $markdown;

        // Cache the result
        if ($settings->cacheTtl > 0) {
            Craft::$app->getCache()->set($cacheKey, $result, $settings->cacheTtl);
        }

        return $result;
    }

    /**
     * Render a listing page as Markdown (list of entries in a section)
     */
    public function renderListingPage(Section $section, Site $site): string
    {
        $settings = LlmReady::getInstance()->getSettings();
        $cacheKey = "llmready:listing:{$site->id}:{$section->id}";

        if ($settings->cacheTtl > 0) {
            $cached = Craft::$app->getCache()->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        $entries = Entry::find()
            ->section($section->handle)
            ->site($site)
            ->status('live')
            ->orderBy('postDate desc')
            ->limit(100)
            ->all();

        $siteName = $site->getName();
        $lines = ["# {$section->name} — {$siteName}", ''];

        foreach ($entries as $entry) {
            $line = $this->formatEntryLink($entry);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        $result = implode("\n", $lines) . "\n";

        if ($settings->cacheTtl > 0) {
            Craft::$app->getCache()->set($cacheKey, $result, $settings->cacheTtl);
        }

        return $result;
    }

    /**
     * Render a dedicated LLM Twig template that outputs raw Markdown
     */
    private function renderLlmTemplate(Entry $entry, string $template): string
    {
        // Sanitize template path to prevent directory traversal
        if (str_contains($template, '..') || str_starts_with($template, '/')) {
            Craft::warning("LLM Ready: Rejected unsafe template path '{$template}'", __METHOD__);

            return $this->buildBasicMarkdown($entry);
        }

        $view = Craft::$app->getView();

        return $view->renderTemplate($template, [
            'entry' => $entry,
        ]);
    }

    /**
     * Convert an entry's rendered HTML to Markdown
     */
    private function convertEntryHtmlToMarkdown(Entry $entry, Site $site): string
    {
        // Render the entry's normal template
        $route = $entry->getRoute();
        if ($route === null) {
            return $this->buildBasicMarkdown($entry);
        }

        // Get the template path from the route
        $template = is_array($route) ? $route[0] : $route;
        if (is_array($route) && str_starts_with($template, 'templates/render')) {
            $template = $route[1]['template'] ?? $template;
        }

        $view = Craft::$app->getView();

        try {
            $html = $view->renderTemplate($template, [
                'entry' => $entry,
            ]);
        } catch (\Throwable $e) {
            Craft::warning("LLM Ready: Failed to render template '{$template}' for entry {$entry->id}: {$e->getMessage()}", __METHOD__);

            return $this->buildBasicMarkdown($entry);
        }

        // Extract main content using configured selectors
        $contentHtml = $this->extractMainContent($html);

        // Convert HTML to Markdown
        return $this->htmlToMarkdown($contentHtml);
    }

    /**
     * Build basic Markdown from entry fields when template rendering fails or is unavailable
     */
    private function buildBasicMarkdown(Entry $entry): string
    {
        $lines = ["# {$entry->title}", ''];

        // Try to get content from common field handles
        foreach (['body', 'content', 'text', 'description', 'summary'] as $handle) {
            try {
                $value = $entry->getFieldValue($handle);
                if ($value && is_string($value)) {
                    $lines[] = $this->htmlToMarkdown($value);
                    break;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Extract main content from full HTML using CSS selectors
     */
    private function extractMainContent(string $html): string
    {
        $settings = LlmReady::getInstance()->getSettings();
        $selectors = array_map('trim', explode(',', $settings->contentSelector));

        $dom = new \DOMDocument();

        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        foreach ($selectors as $selector) {
            $xpathQuery = $this->cssSelectorToXPath($selector);
            $nodes = $xpath->query($xpathQuery);

            if ($nodes !== false && $nodes->length > 0) {
                $content = '';
                foreach ($nodes as $node) {
                    $content .= $dom->saveHTML($node);
                }

                return $content;
            }
        }

        // Fallback: try to find body content
        $body = $xpath->query('//body');
        if ($body !== false && $body->length > 0) {
            return $dom->saveHTML($body->item(0));
        }

        // Last resort: return the full HTML
        return $html;
    }

    /**
     * Convert a simple CSS selector to an XPath expression
     * Supports: tag, .class, #id, [attr="val"], tag.class, tag#id
     */
    private function cssSelectorToXPath(string $selector): string
    {
        $selector = trim($selector);

        // ID selector: #id or tag#id — IDs are restricted to word chars and hyphens
        if (preg_match('/^(\w+)?#([\w-]+)$/', $selector, $matches)) {
            $tag = $matches[1] ?: '*';
            $id = $this->xpathEscapeString($matches[2]);

            return "//{$tag}[@id={$id}]";
        }

        // Class selector: .class or tag.class — classes are restricted to word chars and hyphens
        if (preg_match('/^(\w+)?\.([\w-]+)$/', $selector, $matches)) {
            $tag = $matches[1] ?: '*';
            $class = $this->xpathEscapeString(' ' . $matches[2] . ' ');

            return "//{$tag}[contains(concat(' ', normalize-space(@class), ' '), {$class})]";
        }

        // Attribute selector: [role="main"] — restrict attr values to safe characters
        if (preg_match('/^\[([\w-]+)=["\']([^"\']+)["\']\]$/', $selector, $matches)) {
            $attr = preg_replace('/[^\w-]/', '', $matches[1]);
            $val = $this->xpathEscapeString($matches[2]);

            return "//*[@{$attr}={$val}]";
        }

        // Tag selector: main, article, etc. — must be a single word
        if (preg_match('/^\w+$/', $selector)) {
            return "//{$selector}";
        }

        // Fallback: reject unknown selectors to prevent injection
        Craft::warning("LLM Ready: Skipping unrecognized CSS selector '{$selector}'", __METHOD__);

        return "//___llmready_no_match___";
    }

    /**
     * Safely escape a string for use in an XPath expression
     */
    private function xpathEscapeString(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'{$value}'";
        }

        if (!str_contains($value, '"')) {
            return "\"{$value}\"";
        }

        // Value contains both quote types — use concat()
        $parts = explode("'", $value);
        $escaped = implode("', \"'\", '", $parts);

        return "concat('{$escaped}')";
    }

    /**
     * Convert HTML string to Markdown
     */
    private function htmlToMarkdown(string $html): string
    {
        if ($this->_converter === null) {
            $this->_converter = new HtmlConverter([
                'strip_tags' => true,
                'header_style' => 'atx',
                'remove_nodes' => 'script style nav footer header audio video iframe form svg',
            ]);
        }

        $markdown = $this->_converter->convert($html);

        // Clean up excessive whitespace
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown);

        return trim($markdown) . "\n";
    }

    /**
     * Build YAML front matter for an entry
     */
    public function buildFrontMatter(Entry $entry, Site $site): string
    {
        $lines = ['---'];

        $lines[] = 'title: ' . $this->yamlEscape($entry->title ?? '');

        if ($entry->postDate) {
            $lines[] = 'date: ' . $entry->postDate->format('c');
        }

        // Author
        $author = $entry->getAuthor();
        if ($author) {
            $lines[] = 'author: ' . $this->yamlEscape($author->fullName ?? $author->username);
        }

        // Canonical URL
        $url = $entry->getUrl();
        if ($url) {
            $lines[] = 'canonical_url: ' . $this->yamlEscape($url);
        }

        // Section
        $section = $entry->getSection();
        if ($section) {
            $lines[] = 'section: ' . $this->yamlEscape($section->name);
        }

        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Escape a value for YAML output
     */
    private function yamlEscape(string $value): string
    {
        // Quote if contains special characters
        if (preg_match('/[:#\[\]{}|>&*!,\'"%@`]/', $value) || $value === '') {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }

        return $value;
    }

    /**
     * Get section setting from project config for a section/site combination
     *
     * @return array{enabled: bool, llmTemplate: string|null}|null
     */
    public function getSectionConfig(int $sectionId, int $siteId): ?array
    {
        $section = Craft::$app->getEntries()->getSectionById($sectionId);
        $site = Craft::$app->getSites()->getSiteById($siteId);

        if ($section === null || $site === null) {
            return null;
        }

        $configPath = LlmReady::PROJECT_CONFIG_PATH . ".{$section->uid}.{$site->uid}";

        return Craft::$app->getProjectConfig()->get($configPath);
    }

    /**
     * Check if a section is enabled for LLM output
     */
    public function isSectionEnabled(int $sectionId, int $siteId): bool
    {
        $config = $this->getSectionConfig($sectionId, $siteId);

        // If no config exists, the section is enabled by default
        if ($config === null) {
            return true;
        }

        return (bool) $config['enabled'];
    }

    /**
     * Get cache key for an entry
     */
    public function getCacheKey(Entry $entry, Site $site): string
    {
        $dateUpdated = $entry->dateUpdated ? $entry->dateUpdated->getTimestamp() : '0';

        return "llmready:{$site->id}:{$entry->id}:{$dateUpdated}";
    }

    /**
     * Format an entry as a Markdown list item link with optional description
     */
    public function formatEntryLink(Entry $entry): ?string
    {
        $url = $entry->getUrl();
        if (!$url || $entry->uri === '__home__') {
            return null;
        }

        $description = $this->getEntryDescription($entry);
        if ($description) {
            return "- [{$entry->title}]({$url}.md): {$description}";
        }

        return "- [{$entry->title}]({$url}.md)";
    }

    /**
     * Get a brief description for an entry, for use in llms.txt and listing pages
     */
    public function getEntryDescription(Entry $entry, int $maxLength = 160): ?string
    {
        $settings = LlmReady::getInstance()->getSettings();
        $text = null;

        // Try configured description field first
        if ($settings->descriptionField !== '') {
            $text = $this->getFieldText($entry, $settings->descriptionField);
        }

        // Auto-extract fallback: find the first text field with usable content
        if ($text === null) {
            $fieldLayout = $entry->getFieldLayout();
            if ($fieldLayout !== null) {
                foreach ($fieldLayout->getCustomFields() as $field) {
                    $fieldClass = get_class($field);
                    if ($field instanceof PlainText
                        || $fieldClass === 'craft\\ckeditor\\Field'
                        || $fieldClass === 'craft\\redactor\\Field'
                    ) {
                        $candidate = $this->getFieldText($entry, $field->handle);
                        if ($candidate !== null && mb_strlen($candidate) >= 20) {
                            $text = $candidate;
                            break;
                        }
                    }
                }
            }
        }

        if ($text === null) {
            return null;
        }

        $text = $this->truncateText($text, $maxLength);

        return mb_strlen($text) >= 10 ? $text : null;
    }

    /**
     * Get plain text from a field value, stripping HTML if needed
     */
    private function getFieldText(Entry $entry, string $handle): ?string
    {
        try {
            $value = $entry->getFieldValue($handle);
        } catch (\Throwable) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $text = strip_tags($value);
        $text = Html::decode($text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    /**
     * Truncate text at a sentence or word boundary
     */
    private function truncateText(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        // Try to end at a sentence boundary within the first $maxLength chars
        $substring = mb_substr($text, 0, $maxLength);
        $lastSentence = mb_strrpos($substring, '. ');
        if ($lastSentence !== false && $lastSentence >= (int) ($maxLength * 0.6)) {
            return mb_substr($text, 0, $lastSentence + 1);
        }

        // Otherwise truncate at the last word boundary
        $lastSpace = mb_strrpos($substring, ' ');
        if ($lastSpace !== false) {
            return mb_substr($text, 0, $lastSpace) . '...';
        }

        return $substring . '...';
    }

    /**
     * Invalidate cache for a specific entry across all sites
     */
    public function invalidateEntryCache(Entry $entry): void
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $cache = Craft::$app->getCache();

        foreach ($sites as $site) {
            $cacheKey = $this->getCacheKey($entry, $site);
            $cache->delete($cacheKey);

            // Also invalidate listing page cache for this section
            if ($entry->sectionId) {
                $cache->delete("llmready:listing:{$site->id}:{$entry->sectionId}");
            }
        }

        // Invalidate llms.txt cache
        foreach ($sites as $site) {
            $cache->delete("llmready:llmstxt:{$site->id}");
        }
    }
}
