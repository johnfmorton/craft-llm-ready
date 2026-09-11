<?php

declare(strict_types=1);

namespace johnfmorton\llmready;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\errors\SiteNotFoundException;
use craft\events\ConfigEvent;
use craft\events\DeleteSiteEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\models\Site;
use craft\services\Dashboard;
use craft\services\Sites;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use johnfmorton\llmready\models\Settings;
use johnfmorton\llmready\records\SectionSettingRecord;
use johnfmorton\llmready\services\AnalyticsService;
use johnfmorton\llmready\services\CacheDetectionService;
use johnfmorton\llmready\services\DetectionService;
use johnfmorton\llmready\services\LlmsTxtService;
use johnfmorton\llmready\services\MarkdownService;
use johnfmorton\llmready\services\SeoService;
use johnfmorton\llmready\utilities\CacheCheckUtility;
use johnfmorton\llmready\variables\LlmReadyVariable;
use johnfmorton\llmready\web\assets\webmcp\WebMcpAsset;
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
 * @property-read CacheDetectionService $cacheDetectionService
 * @property-read AnalyticsService $analyticsService
 * @property-read SeoService $seoService
 */
class LlmReady extends Plugin
{
    public const PROJECT_CONFIG_PATH = 'llm-ready.sectionSettings';

    public const PERMISSION_VIEW_ANALYTICS = 'llm-ready:viewAnalytics';
    public const PERMISSION_PURGE_ANALYTICS = 'llm-ready:purgeAnalytics';

    public string $schemaVersion = '1.4.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * Memoized result of resolving the current request's path to an Entry.
     * `null` = not resolved yet; `false` = resolved, no entry. Shared by the
     * discovery-tag and WebMCP injection handlers so a page render performs
     * the URI lookup once.
     */
    private Entry|false|null $resolvedPageEntry = null;

    public static function config(): array
    {
        return [
            'components' => [
                'markdownService' => MarkdownService::class,
                'llmsTxtService' => LlmsTxtService::class,
                'detectionService' => DetectionService::class,
                'cacheDetectionService' => CacheDetectionService::class,
                'analyticsService' => AnalyticsService::class,
                'seoService' => SeoService::class,
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
            $this->registerWebMcpInjection();
            $this->registerTemplateVariable();
        }

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpUrlRules();
        }

        $this->registerUserPermissions();
        $this->registerDashboardWidget();
        $this->registerUtilities();
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
        // Settings present in config/llm-ready.php override the control
        // panel; the template flags each such field and disables it.
        $configOverrides = Craft::$app->getConfig()->getConfigFromFile('llm-ready');

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
            'cacheCheck' => $this->cacheDetectionService->getCheckData(),
            'configOverrides' => $configOverrides,
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
                // Leaving the rules unregistered is what makes a disabled
                // llms.txt 404 — there is no route for Craft to match.
                if ($this->getSettings()->enableLlmsTxt) {
                    // Route for /llms.txt
                    $event->rules['llms.txt'] = 'llm-ready/markdown/llms-txt';

                    // Redirect /.well-known/llms.txt → /llms.txt (RFC 8615)
                    $event->rules['.well-known/llms.txt'] = 'llm-ready/markdown/well-known-llms-txt';
                }

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
     * Register the cache check utility.
     *
     * The utility matters most in production, where `allowAdminChanges` is
     * typically off and the Settings section (with the same summary) is
     * hidden entirely — opening the utility there is what records that
     * environment's result for other environments to see.
     */
    private function registerUtilities(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = CacheCheckUtility::class;
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

                        // A noindex entry falls through to the normal HTML
                        // response rather than being negotiated into Markdown.
                        if ($this->seoService->isNoindex($element)) {
                            return;
                        }

                        $content = $this->markdownService->renderMarkdown($element, $site);

                        $response = Craft::$app->getResponse();
                        $response->format = \yii\web\Response::FORMAT_RAW;
                        $response->getHeaders()->set('Content-Type', 'text/markdown; charset=utf-8');

                        // Markdown on the canonical URL varies by UA/Accept;
                        // keep it out of shared caches to avoid poisoning (#24).
                        $response->getHeaders()->set('Cache-Control', 'private, no-store');
                        $response->getHeaders()->set('Vary', 'User-Agent, Accept');

                        if ($settings->noindexHeader) {
                            $response->getHeaders()->set('X-Robots-Tag', 'noindex');
                        }

                        $response->getHeaders()->set('Link', "<{$element->getUrl()}>; rel=\"canonical\"");

                        $response->data = $content;

                        // Cache headers don't reach a page cache running
                        // inside Craft itself, so ask it directly.
                        $this->preventPageCaching();

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
     * Stop an in-Craft page cache from storing the Markdown response that was
     * just built for the canonical URL.
     *
     * `Cache-Control: private, no-store` handles shared caches sitting in
     * front of the site, but a page cache running inside Craft never sees
     * those headers. Blitz decides what to store from the response *format*
     * plus its own URI patterns — `getIsCacheableResponse()` does not look at
     * `Cache-Control` at all. Its default of `cacheNonHtmlResponses = false`
     * happens to spare us, since this response is `FORMAT_RAW` rather than
     * `FORMAT_HTML`, but that is incidental: turning that documented setting
     * on is enough for Blitz to store Markdown under the canonical URI and
     * serve it to every subsequent visitor — under `Content-Type: text/html`,
     * so browsers try to render it as a page.
     *
     * So opt out through Blitz's own API rather than relying on a header it
     * never reads. The `.md` URLs and `/llms.txt` are deliberately left
     * cacheable: each is its own URL with a single representation.
     */
    private function preventPageCaching(): void
    {
        $blitzClass = '\putyourlightson\blitz\Blitz';

        if (!class_exists($blitzClass) || !Craft::$app->getPlugins()->isPluginEnabled('blitz')) {
            return;
        }

        try {
            $blitzClass::$plugin->generateCache->options->cachingEnabled = false;
            // PHPStan resolves nothing through the dynamic class string and so
            // sees no throw above, but Yii's __get() raises
            // UnknownPropertyException for a component that isn't defined —
            // exactly the Blitz API change this guards against.
            // @phpstan-ignore catch.neverThrown
        } catch (\Throwable $e) {
            Craft::warning(
                "LLM Ready: could not opt out of Blitz page caching: {$e->getMessage()}",
                __METHOD__,
            );
        }
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
                $site = Craft::$app->getSites()->getCurrentSite();
                $element = $this->resolvePageEntry();

                if ($element === null) {
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

                // Don't advertise a Markdown alternate for a noindex entry —
                // its .md URL 404s.
                if ($this->seoService->isNoindex($element)) {
                    return;
                }

                // The home page's alternate is /llms.txt — there is no `.md`
                // for the bare home page, since the catch-all rule matches
                // `.+`. So with llms.txt off the home page has no Markdown
                // alternate to advertise at all.
                $isHome = $element->uri === '__home__';
                if ($isHome && !$settings->enableLlmsTxt) {
                    return;
                }

                $alternateUrl = $isHome
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
     * Resolve the current request's path to an Entry, once per request.
     */
    private function resolvePageEntry(): ?Entry
    {
        if ($this->resolvedPageEntry === null) {
            $path = Craft::$app->getRequest()->getPathInfo();
            $site = Craft::$app->getSites()->getCurrentSite();
            $element = Craft::$app->getElements()->getElementByUri($path ?: '__home__', $site->id);

            $this->resolvedPageEntry = $element instanceof Entry ? $element : false;
        }

        return $this->resolvedPageEntry ?: null;
    }

    /**
     * Inject the WebMCP tool bootstrap on site pages.
     *
     * Registers read-only tools for in-browser AI agents: a site-wide
     * `get-site-overview` (backed by /llms.txt) on every rendered page, and
     * `get-page-content` on entry pages. The page tool's visibility rules
     * are deliberately the same as the discovery tag's — it only registers
     * when the entry's `.md` URL would actually serve (enabled section, live
     * entry with a URL, not noindex) — and both tools fetch existing public
     * URLs, so an in-browser agent can never reach content the crawler
     * surface hides.
     */
    private function registerWebMcpInjection(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                $settings = $this->getSettings();
                if (!$settings->autoInjectWebMcp) {
                    return;
                }

                $config = $this->getWebMcpConfig();
                if ($config === null) {
                    return;
                }

                $view = Craft::$app->getView();

                if ($settings->webMcpOriginTrialToken !== '') {
                    $view->registerMetaTag([
                        'http-equiv' => 'origin-trial',
                        'content' => $settings->webMcpOriginTrialToken,
                    ], 'llm-ready-webmcp-ot');
                }

                $view->registerAssetBundle(WebMcpAsset::class);

                $view->registerScript(
                    $this->encodeWebMcpConfig($config),
                    View::POS_END,
                    ['type' => 'application/json', 'id' => 'llm-ready-webmcp'],
                    'llm-ready-webmcp',
                );
            },
        );
    }

    /**
     * Expose `craft.llmReady` in site templates.
     */
    private function registerTemplateVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('llmReady', LlmReadyVariable::class);
            },
        );
    }

    /**
     * Decide which WebMCP tools the current request should offer.
     *
     * Returns the configuration the bootstrap script reads from its JSON
     * data island (`llmsTxtUrl`, `pageMarkdownUrl`), or `null` when nothing
     * should be injected: plugin or WebMCP disabled, a non-GET request, or
     * no tool applying to the page. The page tool's visibility rules are
     * deliberately the same as the discovery tag's — it only registers when
     * the entry's `.md` URL would actually serve (enabled section, live entry
     * with a URL, not noindex) — and both tools fetch existing public URLs,
     * so an in-browser agent can never reach content the crawler surface
     * hides.
     *
     * @param Entry|null $entry The page's entry, when the template knows it
     *   (custom routes). Defaults to resolving the request path.
     * @return array<string, string>|null
     */
    public function getWebMcpConfig(?Entry $entry = null): ?array
    {
        $settings = $this->getSettings();
        if (!$settings->enabled || !$settings->enableWebMcp) {
            return null;
        }

        if (!Craft::$app->getRequest()->getIsGet()) {
            return null;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $config = [];

        // Site overview tool — any rendered page, when llms.txt serves
        if ($settings->enableLlmsTxt) {
            $baseUrl = $site->getBaseUrl();
            if ($baseUrl) {
                $config['llmsTxtUrl'] = rtrim($baseUrl, '/') . '/llms.txt';
            }
        }

        // Page content tool — entry pages whose .md URL would serve. The
        // bare home page has no .md URL (the catch-all rule matches `.+`),
        // so it offers no page tool. A URI lookup only returns live
        // elements; an entry handed in by a template is checked explicitly.
        $element = $entry ?? $this->resolvePageEntry();
        if (
            $element !== null
            && $element->uri !== '__home__'
            && ($entry === null || $element->getStatus() === Entry::STATUS_LIVE)
            && $this->markdownService->isSectionEnabled($element->sectionId, $element->siteId)
            && !$this->seoService->isNoindex($element)
        ) {
            $url = $element->getUrl();
            if ($url) {
                $config['pageMarkdownUrl'] = rtrim($url, '/') . '.md';
            }
        }

        return $config === [] ? null : $config;
    }

    /**
     * Render the WebMCP bootstrap as literal markup, for templates that
     * place it themselves via `{{ craft.llmReady.webMcp() }}`.
     *
     * Same three pieces the automatic injection registers — origin-trial
     * meta tag (when a token is set), JSON data island, deferred script —
     * but emitted inline so they land wherever the tag sits and on any
     * render path, including templates that never pass through Craft's
     * page pipeline. Empty when {@see getWebMcpConfig()} decides nothing
     * applies.
     */
    public function renderWebMcpHtml(?Entry $entry = null): string
    {
        $config = $this->getWebMcpConfig($entry);
        if ($config === null) {
            return '';
        }

        $settings = $this->getSettings();
        $assetManager = Craft::$app->getAssetManager();
        $bundle = $assetManager->getBundle(WebMcpAsset::class);
        $scriptUrl = $assetManager->getAssetUrl($bundle, 'js/webmcp.js');

        $html = '';

        if ($settings->webMcpOriginTrialToken !== '') {
            $html .= Html::tag('meta', '', [
                'http-equiv' => 'origin-trial',
                'content' => $settings->webMcpOriginTrialToken,
            ]) . "\n";
        }

        $html .= Html::script($this->encodeWebMcpConfig($config), [
            'type' => 'application/json',
            'id' => 'llm-ready-webmcp',
        ]) . "\n";

        $html .= Html::jsFile($scriptUrl, ['defer' => true]) . "\n";

        return $html;
    }

    /**
     * JSON-encode the data island. JSON_HEX_TAG keeps a literal `</script>`
     * inside any value from breaking out of it.
     *
     * @param array<string, string> $config
     */
    private function encodeWebMcpConfig(array $config): string
    {
        return Json::encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG,
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

        // The Site Description setting can be a template that reads a global
        // set, so an editor's change to one must reach /llms.txt without
        // waiting out the cache TTL.
        Event::on(
            GlobalSet::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                $this->llmsTxtService->invalidateCache();
            },
        );
    }
}
