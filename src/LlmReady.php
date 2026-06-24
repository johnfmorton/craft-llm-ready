<?php

declare(strict_types=1);

namespace johnfmorton\llmready;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\errors\SiteNotFoundException;
use craft\events\ConfigEvent;
use craft\events\DeleteSiteEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\models\Site;
use craft\services\Dashboard;
use craft\services\Sites;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use johnfmorton\llmready\models\Settings;
use johnfmorton\llmready\records\SectionSettingRecord;
use johnfmorton\llmready\services\AnalyticsService;
use johnfmorton\llmready\services\DetectionService;
use johnfmorton\llmready\services\LlmsTxtService;
use johnfmorton\llmready\services\MarkdownService;
use johnfmorton\llmready\widgets\AnalyticsWidget;
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

    public const PERMISSION_VIEW_ANALYTICS = 'llm-ready:viewAnalytics';
    public const PERMISSION_PURGE_ANALYTICS = 'llm-ready:purgeAnalytics';

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

        $this->registerUserPermissions();
        $this->registerDashboardWidget();
        $this->registerCacheInvalidation();
        $this->registerProjectConfigListeners();
        $this->registerSiteListeners();
    }

    public function getCpNavItem(): ?array
    {
        if (!$this->getSettings()->enableAnalytics) {
            return null;
        }

        if (!Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW_ANALYTICS)) {
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
     * Register custom user permissions for the analytics dashboard
     */
    private function registerUserPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'LLM Ready',
                    'permissions' => [
                        self::PERMISSION_VIEW_ANALYTICS => [
                            'label' => 'View the analytics dashboard',
                            'nested' => [
                                self::PERMISSION_PURGE_ANALYTICS => [
                                    'label' => 'Purge analytics data',
                                ],
                            ],
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Register the dashboard widget so admins can drop it on their CP Dashboard
     */
    private function registerDashboardWidget(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = AnalyticsWidget::class;
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
                                $request->getPathInfo() ?: '__home__',
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
     * Advertise the Markdown alternate to AI crawlers, via the HTML
     * `<link rel="alternate">` tag and/or the HTTP `Link` header (RFC 8288).
     * Both are independently toggleable in plugin settings; default-on.
     */
    private function registerDiscoveryTagInjection(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                $settings = $this->getSettings();
                if (!$settings->enabled) {
                    return;
                }
                if (!$settings->autoInjectDiscoveryTag && !$settings->autoInjectLinkHeader) {
                    return;
                }

                $request = Craft::$app->getRequest();

                // Allow GET and HEAD — per RFC 9110, HEAD must return the same
                // headers as GET, and some clients (monitoring, link-checkers,
                // `curl -I`) only issue HEAD.
                if (!$request->getIsGet() && !$request->getIsHead()) {
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

                $alternateUrl = $element->uri === '__home__'
                    ? rtrim($url, '/') . '/llms.txt'
                    : rtrim($url, '/') . '.md';

                if ($settings->autoInjectDiscoveryTag) {
                    Craft::$app->getView()->registerLinkTag([
                        'rel' => 'alternate',
                        'type' => 'text/markdown',
                        'href' => $alternateUrl,
                    ]);
                }

                if ($settings->autoInjectLinkHeader) {
                    // `add` (not `set`) because Link can carry multiple values
                    // — preserves anything else already on the response.
                    Craft::$app->getResponse()->getHeaders()->add(
                        'Link',
                        "<{$alternateUrl}>; rel=\"alternate\"; type=\"text/markdown\"",
                    );
                }
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
     * Register site event listeners to keep project config in sync
     */
    private function registerSiteListeners(): void
    {
        Event::on(
            Sites::class,
            Sites::EVENT_AFTER_DELETE_SITE,
            [$this, 'handleDeletedSite'],
        );
    }

    /**
     * Returns a site by its UID, or null if no such site exists.
     *
     * Sites::getSiteByUid() throws for an unknown UID rather than returning null.
     */
    public static function siteByUidOrNull(string $uid): ?Site
    {
        try {
            return Craft::$app->getSites()->getSiteByUid($uid);
        } catch (SiteNotFoundException) {
            return null;
        }
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
        $site = self::siteByUidOrNull($siteUid);

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
        $site = self::siteByUidOrNull($siteUid);

        if ($section === null || $site === null) {
            return;
        }

        SectionSettingRecord::deleteAll([
            'sectionId' => $section->id,
            'siteId' => $site->id,
        ]);
    }

    /**
     * Prune orphaned section settings from project config when a site is deleted.
     *
     * Craft leaves nested plugin config behind on site deletion; the matching DB
     * rows are already dropped via the section_settings siteId foreign key.
     */
    public function handleDeletedSite(DeleteSiteEvent $event): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // Project config is read-only while it is being applied; the source
        // environment is responsible for the removal in that case.
        if ($projectConfig->readOnly) {
            return;
        }

        $siteUid = $event->site->uid;
        $sectionSettings = $projectConfig->get(self::PROJECT_CONFIG_PATH) ?? [];

        foreach (array_keys($sectionSettings) as $sectionUid) {
            $projectConfig->remove(self::PROJECT_CONFIG_PATH . ".{$sectionUid}.{$siteUid}");
        }
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
