<?php

declare(strict_types=1);

namespace johnfmorton\llmready\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use johnfmorton\llmready\LlmReady;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves Markdown responses for entries and llms.txt
 */
class MarkdownController extends Controller
{
    /** @inheritdoc */
    protected array|bool|int $allowAnonymous = true;

    /**
     * Serve Markdown for an entry resolved by its URI path
     */
    public function actionServe(string $path = ''): Response
    {
        $plugin = LlmReady::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled) {
            throw new NotFoundHttpException();
        }

        $site = Craft::$app->getSites()->getCurrentSite();

        // Try to resolve the path as an entry
        $element = Craft::$app->getElements()->getElementByUri($path, $site->id);

        if ($element instanceof Entry) {
            return $this->serveEntry($element, $site);
        }

        // Try to match a section for listing pages
        $section = $this->resolveSectionFromPath($path, $site);
        if ($section !== null) {
            return $this->serveListingPage($section, $site);
        }

        throw new NotFoundHttpException();
    }

    /**
     * Serve the /llms.txt file
     */
    public function actionLlmsTxt(): Response
    {
        $plugin = LlmReady::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled) {
            throw new NotFoundHttpException();
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $content = $plugin->llmsTxtService->generate($site);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/markdown; charset=utf-8');

        if ($settings->noindexHeader) {
            $response->getHeaders()->set('X-Robots-Tag', 'noindex');
        }

        $response->data = $content;

        return $response;
    }

    /**
     * Serve Markdown for an entry with permission checks
     */
    private function serveEntry(Entry $entry, \craft\models\Site $site): Response
    {
        $plugin = LlmReady::getInstance();
        $settings = $plugin->getSettings();
        $markdownService = $plugin->markdownService;

        // Check if section is enabled
        if (!$markdownService->isSectionEnabled($entry->sectionId, $site->id)) {
            throw new NotFoundHttpException();
        }

        // Only serve live entries
        if ($entry->status !== Entry::STATUS_LIVE) {
            throw new NotFoundHttpException();
        }

        // Respect permissions — if entry is not viewable by the current user, return 403
        if (!$entry->getUrl()) {
            throw new NotFoundHttpException();
        }

        $content = $markdownService->renderMarkdown($entry, $site);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/markdown; charset=utf-8');

        if ($settings->noindexHeader) {
            $response->getHeaders()->set('X-Robots-Tag', 'noindex');
        }

        // Add alternate link header pointing to the HTML version
        $response->getHeaders()->set('Link', "<{$entry->getUrl()}>; rel=\"canonical\"");

        $response->data = $content;

        return $response;
    }

    /**
     * Serve a listing page for a section
     */
    private function serveListingPage(\craft\models\Section $section, \craft\models\Site $site): Response
    {
        $plugin = LlmReady::getInstance();
        $settings = $plugin->getSettings();
        $markdownService = $plugin->markdownService;

        if (!$markdownService->isSectionEnabled($section->id, $site->id)) {
            throw new NotFoundHttpException();
        }

        $content = $markdownService->renderListingPage($section, $site);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'text/markdown; charset=utf-8');

        if ($settings->noindexHeader) {
            $response->getHeaders()->set('X-Robots-Tag', 'noindex');
        }

        $response->data = $content;

        return $response;
    }

    /**
     * Try to resolve a URL path to a section (for listing pages)
     */
    private function resolveSectionFromPath(string $path, \craft\models\Site $site): ?\craft\models\Section
    {
        $sections = Craft::$app->getEntries()->getAllSections();

        foreach ($sections as $section) {
            $siteSettings = $section->getSiteSettings();
            if (!isset($siteSettings[$site->id])) {
                continue;
            }

            $siteSetting = $siteSettings[$site->id];
            if (!$siteSetting->hasUrls) {
                continue;
            }

            // Check if the URI format base matches the path
            // For sections with URI format like "blog/{slug}",
            // the listing page would be "blog"
            $uriFormat = $siteSetting->uriFormat;
            $baseUri = $this->getBaseUriFromFormat($uriFormat);

            if ($baseUri !== null && $baseUri === $path) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Extract the base URI from a URI format (e.g., "blog/{slug}" → "blog")
     */
    private function getBaseUriFromFormat(string $uriFormat): ?string
    {
        // Find the first segment before any {variable}
        $pos = strpos($uriFormat, '{');
        if ($pos === false) {
            return null;
        }

        $base = rtrim(substr($uriFormat, 0, $pos), '/');

        return $base !== '' ? $base : null;
    }
}
