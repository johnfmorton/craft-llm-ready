<?php

declare(strict_types=1);

namespace johnfmorton\llmready;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ConfigEvent;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\web\UrlManager;
use craft\web\View;
use johnfmorton\llmready\models\Settings;
use johnfmorton\llmready\records\SectionSettingRecord;
use johnfmorton\llmready\services\AnalyticsService;
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
 * @property-read AnalyticsService $analyticsService
 */
class LlmReady extends Plugin
{
    public const PROJECT_CONFIG_PATH = 'llm-ready.sectionSettings';

    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'markdownService' => MarkdownService::class,
                'llmsTxtService' => LlmsTxtService::class,
                'detectionService' => DetectionService::class,
                'analyticsService' => AnalyticsService::class,
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

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpUrlRules();
        }

        $this->registerCacheInvalidation();
        $this->registerProjectConfigListeners();
    }

    public function getCpNavItem(): ?array
    {
        if (!$this->getSettings()->enableAnalytics) {
            return null;
        }

        return parent::getCpNavItem();
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
        $projectConfig = Craft::$app->getProjectConfig();

        $sectionData = [];
        foreach ($sections as $section) {
            $siteSettings = $section->getSiteSettings();

            // Skip homepage Singles — they can't serve .md URLs
            $isHomepage = false;
            foreach ($siteSettings as $siteSetting) {
                if ($siteSetting->uriFormat === '__home__') {
                    $isHomepage = true;
                    break;
                }
            }
            if ($isHomepage) {
                continue;
            }
            $sectionSites = [];

            foreach ($sites as $site) {
                if (!isset($siteSettings[$site->id])) {
                    continue;
                }

                $siteSetting = $siteSettings[$site->id];
                if (!$siteSetting->hasUrls) {
                    continue;
                }

                // Read from project config using UIDs
                $configPath = self::PROJECT_CONFIG_PATH . ".{$section->uid}.{$site->uid}";
                $config = $projectConfig->get($configPath);

                $sectionSites[] = [
                    'site' => $site,
                    'enabled' => $config['enabled'] ?? true,
                    'llmTemplate' => $config['llmTemplate'] ?? '',
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

        // Save per-section settings to project config
        $request = Craft::$app->getRequest();
        $allSettings = $request->getBodyParam('settings');
        $sectionSettings = $allSettings['sectionSettings'] ?? null;

        if (is_array($sectionSettings)) {
            $projectConfig = Craft::$app->getProjectConfig();
            $sectionsService = Craft::$app->getEntries();
            $sitesService = Craft::$app->getSites();

            foreach ($sectionSettings as $sectionId => $sites) {
                $section = $sectionsService->getSectionById((int) $sectionId);
                if ($section === null) {
                    continue;
                }

                foreach ($sites as $siteId => $values) {
                    $site = $sitesService->getSiteById((int) $siteId);
                    if ($site === null) {
                        continue;
                    }

                    $configPath = self::PROJECT_CONFIG_PATH . ".{$section->uid}.{$site->uid}";
                    $projectConfig->set($configPath, [
                        'enabled' => !empty($values['enabled']),
                        'llmTemplate' => !empty($values['llmTemplate']) ? $values['llmTemplate'] : null,
                    ]);
                }
            }
        }

        // Clear the data cache so template/setting changes take effect immediately.
        Craft::$app->getCache()->flush();
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

                // Redirect /.well-known/llms.txt → /llms.txt (RFC 8615)
                $event->rules['.well-known/llms.txt'] = 'llm-ready/markdown/well-known-llms-txt';

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
     * Register CP URL rules for the analytics dashboard
     */
    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['llm-ready'] = 'llm-ready/analytics/index';
                $event->rules['llm-ready/data'] = 'llm-ready/analytics/data';
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

                        if ($settings->enableAnalytics) {
                            $this->analyticsService->logRequest(
                                $site->id,
                                $element->getCanonicalId(),
                                'negotiated',
                                $this->analyticsService->identifyBot($request),
                                $request->getPathInfo(),
                            );
                        }

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
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
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

                Craft::$app->getView()->registerLinkTag([
                    'rel' => 'alternate',
                    'type' => 'text/markdown',
                    'href' => rtrim($url, '/') . '.md',
                ]);
            },
        );
    }

    /**
     * Register project config event listeners to sync DB from project config
     */
    private function registerProjectConfigListeners(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // Listen for add/update on individual section+site settings
        $projectConfig->onAdd(self::PROJECT_CONFIG_PATH . '.{uid}.{uid}', [$this, 'handleChangedSectionSetting']);
        $projectConfig->onUpdate(self::PROJECT_CONFIG_PATH . '.{uid}.{uid}', [$this, 'handleChangedSectionSetting']);
        $projectConfig->onRemove(self::PROJECT_CONFIG_PATH . '.{uid}.{uid}', [$this, 'handleRemovedSectionSetting']);
    }

    /**
     * Handle a section setting being added or updated in project config
     */
    public function handleChangedSectionSetting(ConfigEvent $event): void
    {
        // Path is llm-ready.sectionSettings.{sectionUid}.{siteUid}
        $sectionUid = $event->tokenMatches[0];
        $siteUid = $event->tokenMatches[1];

        $section = Craft::$app->getEntries()->getSectionByUid($sectionUid);
        /** @var \craft\models\Site|null $site */
        $site = Craft::$app->getSites()->getSiteByUid($siteUid);

        if ($section === null || $site === null) {
            return;
        }

        /** @var SectionSettingRecord|null $record */
        $record = SectionSettingRecord::find()
            ->where([
                'sectionId' => $section->id,
                'siteId' => $site->id,
            ])
            ->one();

        if ($record === null) {
            $record = new SectionSettingRecord();
            $record->sectionId = $section->id;
            $record->siteId = $site->id;
        }

        $record->enabled = $event->newValue['enabled'] ?? true;
        $record->llmTemplate = $event->newValue['llmTemplate'] ?? null;
        $record->save();
    }

    /**
     * Handle a section setting being removed from project config
     */
    public function handleRemovedSectionSetting(ConfigEvent $event): void
    {
        $sectionUid = $event->tokenMatches[0];
        $siteUid = $event->tokenMatches[1];

        $section = Craft::$app->getEntries()->getSectionByUid($sectionUid);
        /** @var \craft\models\Site|null $site */
        $site = Craft::$app->getSites()->getSiteByUid($siteUid);

        if ($section === null || $site === null) {
            return;
        }

        SectionSettingRecord::deleteAll([
            'sectionId' => $section->id,
            'siteId' => $site->id,
        ]);
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
