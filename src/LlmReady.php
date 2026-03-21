<?php

declare(strict_types=1);

namespace johnfmorton\llmready;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\web\UrlManager;
use craft\web\View;
use johnfmorton\llmready\models\Settings;
use johnfmorton\llmready\services\DetectionService;
use johnfmorton\llmready\services\LlmsTxtService;
use johnfmorton\llmready\services\MarkdownService;
use yii\base\ActionEvent;
use yii\base\Event;

/**
 * LLM Ready plugin
 *
 * Serves Markdown versions of Craft CMS pages to AI crawlers and LLMs.
 *
 * @method static LlmReady getInstance()
 * @method Settings getSettings()
 * @property-read MarkdownService $markdownService
 * @property-read LlmsTxtService $llmsTxtService
 * @property-read DetectionService $detectionService
 */
class LlmReady extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'markdownService' => MarkdownService::class,
                'llmsTxtService' => LlmsTxtService::class,
                'detectionService' => DetectionService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Only register front-end handlers for site requests
        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            $this->registerUrlRules();
            $this->registerContentNegotiationHandler();
            $this->registerDiscoveryTagInjection();
        }

        $this->registerCacheInvalidation();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        // Get all sections with their site settings for the template
        $sections = Craft::$app->getEntries()->getAllSections();
        $sites = Craft::$app->getSites()->getAllSites();

        $sectionData = [];
        foreach ($sections as $section) {
            $siteSettings = $section->getSiteSettings();
            $sectionSites = [];

            foreach ($sites as $site) {
                if (!isset($siteSettings[$site->id])) {
                    continue;
                }

                $siteSetting = $siteSettings[$site->id];
                if (!$siteSetting->hasUrls) {
                    continue;
                }

                // Get plugin's per-section setting
                $record = $this->markdownService->getSectionSetting($section->id, $site->id);

                $sectionSites[] = [
                    'site' => $site,
                    'enabled' => $record ? (bool) $record->enabled : true,
                    'llmTemplate' => $record?->llmTemplate ?? '',
                ];
            }

            if (!empty($sectionSites)) {
                $sectionData[] = [
                    'section' => $section,
                    'sites' => $sectionSites,
                ];
            }
        }

        return Craft::$app->getView()->renderTemplate('llm-ready/settings/index', [
            'settings' => $this->getSettings(),
            'sectionData' => $sectionData,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();

        // Save per-section settings from the POST data
        $request = Craft::$app->getRequest();
        $sectionSettings = $request->getBodyParam('sectionSettings');

        if (is_array($sectionSettings)) {
            foreach ($sectionSettings as $sectionId => $sites) {
                foreach ($sites as $siteId => $values) {
                    /** @var \johnfmorton\llmready\records\SectionSettingRecord|null $record */
                    $record = \johnfmorton\llmready\records\SectionSettingRecord::find()
                        ->where([
                            'sectionId' => $sectionId,
                            'siteId' => $siteId,
                        ])
                        ->one();

                    if ($record === null) {
                        $record = new \johnfmorton\llmready\records\SectionSettingRecord();
                        $record->sectionId = (int) $sectionId;
                        $record->siteId = (int) $siteId;
                    }

                    $record->enabled = !empty($values['enabled']);
                    $record->llmTemplate = !empty($values['llmTemplate']) ? $values['llmTemplate'] : null;
                    $record->save();
                }
            }
        }
    }

    /**
     * Register URL rules for .md suffix and /llms.txt
     */
    private function registerUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Route for /llms.txt
                $event->rules['llms.txt'] = 'llm-ready/markdown/llms-txt';

                // Catch-all route for *.md URLs using Yii2 UrlRule with suffix
                // Use .+ (not .*) to avoid matching the bare homepage
                $event->rules[] = [
                    'pattern' => '<path:.+>',
                    'route' => 'llm-ready/markdown/serve',
                    'suffix' => '.md',
                ];

            },
        );
    }

    /**
     * Register handler for content negotiation and user-agent detection
     */
    private function registerContentNegotiationHandler(): void
    {
        Event::on(
            \yii\web\Application::class,
            \yii\web\Application::EVENT_BEFORE_ACTION,
            function(ActionEvent $event) {
                $settings = $this->getSettings();
                if (!$settings->enabled) {
                    return;
                }

                $request = Craft::$app->getRequest();

                // Skip if this is already a .md request (handled by URL rules)
                if ($this->detectionService->isMarkdownUrlRequest($request->getPathInfo())) {
                    return;
                }

                // Skip if not a front-end GET request
                if (!$request->getIsSiteRequest() || !$request->getIsGet()) {
                    return;
                }

                // Check content negotiation and user-agent detection
                if ($this->detectionService->shouldServeMarkdown($request)) {
                    $path = $request->getPathInfo();
                    $site = Craft::$app->getSites()->getCurrentSite();

                    // Resolve the element for this path
                    $element = Craft::$app->getElements()->getElementByUri(
                        $path ?: '__home__',
                        $site->id,
                    );

                    if ($element instanceof Entry) {
                        if (!$this->markdownService->isSectionEnabled($element->sectionId, $site->id)) {
                            return;
                        }

                        if ($element->status !== Entry::STATUS_LIVE || !$element->getUrl()) {
                            return;
                        }

                        $content = $this->markdownService->renderMarkdown($element, $site);

                        $response = Craft::$app->getResponse();
                        $response->format = \yii\web\Response::FORMAT_RAW;
                        $response->getHeaders()->set('Content-Type', 'text/markdown; charset=utf-8');

                        if ($settings->noindexHeader) {
                            $response->getHeaders()->set('X-Robots-Tag', 'noindex');
                        }

                        $response->getHeaders()->set('Link', "<{$element->getUrl()}>; rel=\"canonical\"");

                        $response->data = $content;
                        $response->send();
                        Craft::$app->end();
                    }
                }
            },
        );
    }

    /**
     * Register discovery tag injection into HTML responses
     */
    private function registerDiscoveryTagInjection(): void
    {
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                $settings = $this->getSettings();
                if (!$settings->enabled || !$settings->autoInjectDiscoveryTag) {
                    return;
                }

                $request = Craft::$app->getRequest();

                // Don't inject into .md requests or non-GET requests
                if (!$request->getIsGet()) {
                    return;
                }

                // Find the matched element
                $path = $request->getPathInfo();
                $site = Craft::$app->getSites()->getCurrentSite();
                $element = Craft::$app->getElements()->getElementByUri($path ?: '__home__', $site->id);

                if (!($element instanceof Entry)) {
                    return;
                }

                // Check if section is enabled
                if (!$this->markdownService->isSectionEnabled($element->sectionId, $site->id)) {
                    return;
                }

                $url = $element->getUrl();
                if (!$url) {
                    return;
                }

                // Inject the link tag before </head>
                $linkTag = '<link rel="alternate" type="text/markdown" href="' . $url . '.md">';
                $event->output = str_replace('</head>', $linkTag . "\n</head>", $event->output);
            },
        );
    }

    /**
     * Register cache invalidation on entry save
     */
    private function registerCacheInvalidation(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                $this->markdownService->invalidateEntryCache($entry);
            },
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                $this->markdownService->invalidateEntryCache($entry);
            },
        );
    }
}
