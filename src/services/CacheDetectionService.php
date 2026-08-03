<?php

declare(strict_types=1);

namespace johnfmorton\llmready\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\web\Request;
use DateTime;
use johnfmorton\llmready\records\CacheCheckRecord;
use yii\base\Component;

/**
 * Detects whether a shared cache sits in front of the site (issue #33).
 *
 * "AI Bot User-Agent Detection" is safe on origin-only sites and unsafe
 * behind a caching edge, because a `User-Agent`-varying response on the
 * canonical URL can be stored by a shared cache and replayed to real
 * visitors as raw Markdown (#24). Most of the evidence needed to make that
 * call is available to the plugin, so it shouldn't be the site owner's
 * homework. Three tiers:
 *
 * - Tier 1: inspect the current request's headers for proxy fingerprints
 *   (CF-Ray, Fastly-Client-IP, X-Varnish, Via, …). Free, no network.
 * - Tier 2: read the configuration of page caches running inside Craft
 *   (Blitz). Cache headers never reach an in-Craft cache, so this asks the
 *   plugin directly rather than inferring.
 * - Tier 3: an active probe — request a public URL twice with a browser
 *   User-Agent and look for cache-hit evidence on the second response. The
 *   only actual proof, and the only tier that costs a network request, so
 *   it runs behind a button.
 *
 * Results are persisted per `CRAFT_ENVIRONMENT` (in the DB, never project
 * config — this is environment state that must not sync), so a developer in
 * dev can see what the last check in production found.
 *
 * The one thing every consumer of these results must respect: absence of
 * evidence is not absence of a cache. An nginx `proxy_cache`, or a Varnish
 * configured not to add `Via`, is invisible to every tier here. A positive
 * detection is confident; a negative one is only "nothing detected".
 */
class CacheDetectionService extends Component
{
    /**
     * Finding levels. A `warning` is confident evidence of a shared cache or
     * CDN edge; a `notice` is a signal worth knowing about that doesn't by
     * itself prove caching (e.g. a generic `Via` proxy).
     */
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_NOTICE = 'notice';

    /**
     * Probe verdicts.
     */
    public const PROBE_HIT = 'hit';       // second response was served from cache — proof
    public const PROBE_PROXY = 'proxy';   // a proxy/CDN answered, but no cache hit observed
    public const PROBE_NONE = 'none';     // no evidence either way
    public const PROBE_ERROR = 'error';   // the probe could not complete

    /**
     * Request headers a proxying edge stamps on its way to the origin,
     * grouped by the cache they identify. Header presence is the signal;
     * values are recorded as evidence.
     *
     * Deliberately absent: `X-Forwarded-For` and friends — every load
     * balancer adds those, caching or not, so they prove nothing here.
     *
     * @var array<array{label: string, headers: string[]}>
     */
    private const HEADER_SIGNATURES = [
        [
            'label' => 'Cloudflare (proxied)',
            'headers' => ['CF-Ray', 'CF-Connecting-IP', 'CF-Visitor', 'CF-IPCountry', 'CF-Worker'],
        ],
        [
            'label' => 'Fastly',
            'headers' => ['Fastly-Client-IP', 'Fastly-FF', 'Fastly-SSL'],
        ],
        [
            'label' => 'Varnish',
            'headers' => ['X-Varnish'],
        ],
        [
            'label' => 'Akamai (or another CDN in enterprise mode)',
            'headers' => ['True-Client-IP', 'Akamai-Origin-Hop'],
        ],
    ];

    /**
     * The User-Agent the active probe sends. A plausible desktop browser —
     * NEVER a bot UA. A bot-UA probe would generate exactly the Markdown
     * response this feature exists to keep out of shared caches; the
     * diagnostic would cause the bug it is diagnosing. A browser-UA probe
     * can only ever cause HTML to be cached, which is harmless.
     */
    private const PROBE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    /**
     * `CF-Cache-Status` values that mean Cloudflare served the response from
     * its cache rather than the origin.
     */
    private const CF_CACHE_HIT_STATUSES = ['HIT', 'STALE', 'UPDATING', 'REVALIDATED'];

    /**
     * The current environment name (`CRAFT_ENVIRONMENT`), bounded to the DB
     * column length.
     */
    public function getCurrentEnvironment(): string
    {
        $env = Craft::$app->env ?? 'unknown';

        return mb_substr($env, 0, 64);
    }

    /**
     * The URL the probe targets when none is supplied — used to prefill the
     * probe URL field in the UI.
     */
    public function getDefaultProbeUrl(): string
    {
        try {
            return Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Everything the settings banner and the utility need: a fresh passive
     * evaluation of this environment (persisted as a side effect), the
     * stored results from other environments, and this environment's last
     * probe result.
     *
     * @return array{
     *     currentEnv: string,
     *     findings: array<array{level: string, label: string, evidence: string}>,
     *     otherResults: array<array{environment: string, findings: array, passiveDate: DateTime|null, probe: array|null, probeDate: DateTime|null}>,
     *     probe: array|null,
     *     probeDate: DateTime|null,
     *     probeDefaultUrl: string,
     *     status: array{level: string, text: string},
     * }
     */
    public function getCheckData(): array
    {
        $currentEnv = $this->getCurrentEnvironment();
        $findings = $this->evaluateCurrentEnvironment();

        $otherResults = [];
        $currentProbe = null;
        $currentProbeDate = null;

        foreach ($this->getStoredResults() as $result) {
            if ($result['environment'] === $currentEnv) {
                $currentProbe = $result['probe'];
                $currentProbeDate = $result['probeDate'];
            } else {
                $otherResults[] = $result;
            }
        }

        return [
            'currentEnv' => $currentEnv,
            'findings' => $findings,
            'otherResults' => $otherResults,
            'probe' => $currentProbe,
            'probeDate' => $currentProbeDate,
            'probeDefaultUrl' => $this->getDefaultProbeUrl(),
            'status' => $this->getStatus($findings, $currentProbe),
        ];
    }

    /**
     * Run the passive tiers (1 and 2) for the current environment and
     * persist the result keyed by environment name.
     *
     * @return array<array{level: string, label: string, evidence: string}>
     */
    public function evaluateCurrentEnvironment(): array
    {
        $findings = [];

        $request = Craft::$app->getRequest();
        if ($request instanceof Request) {
            $findings = $this->inspectRequestHeaders($request);
        }

        $findings = array_merge($findings, $this->inspectPageCaches());

        $this->persist($this->getCurrentEnvironment(), passive: ['findings' => $findings]);

        return $findings;
    }

    /**
     * Tier 1: look for proxy fingerprints in the current request's headers.
     *
     * This cleanly separates the two Cloudflare modes, which is the
     * distinction that matters most in practice: DNS-only Cloudflare never
     * touches their edge and sends no `CF-*` headers; orange-cloud proxying
     * always does. A site that is safe today starts reporting a warning the
     * moment the proxy is switched on — exactly when the owner needs to know.
     *
     * @return array<array{level: string, label: string, evidence: string}>
     */
    public function inspectRequestHeaders(Request $request): array
    {
        $findings = [];
        $headers = $request->getHeaders();

        foreach (self::HEADER_SIGNATURES as $signature) {
            $seen = [];
            foreach ($signature['headers'] as $name) {
                $value = $headers->get($name);
                if ($value !== null && $value !== '') {
                    $seen[] = "{$name}: " . $this->truncate($value);
                }
            }

            if ($seen !== []) {
                $findings[] = [
                    'level' => self::LEVEL_WARNING,
                    'label' => $signature['label'],
                    'evidence' => 'This request arrived with ' . implode(', ', $seen) . ' — stamped by the proxy on its way to the origin.',
                ];
            }
        }

        // CDN-Loop (RFC 8586) names the CDN in its value.
        $cdnLoop = $headers->get('CDN-Loop');
        if ($cdnLoop !== null && $cdnLoop !== '' && !$this->alreadyFoundCloudflare($findings, $cdnLoop)) {
            $findings[] = [
                'level' => self::LEVEL_WARNING,
                'label' => 'CDN proxy',
                'evidence' => 'This request arrived with CDN-Loop: ' . $this->truncate($cdnLoop) . ' — a CDN identified itself per RFC 8586.',
            ];
        }

        // Surrogate-Capability exists to announce a caching surrogate.
        $surrogate = $headers->get('Surrogate-Capability');
        if ($surrogate !== null && $surrogate !== '') {
            $findings[] = [
                'level' => self::LEVEL_WARNING,
                'label' => 'Caching surrogate',
                'evidence' => 'This request arrived with Surrogate-Capability: ' . $this->truncate($surrogate) . ' — a surrogate cache announced itself.',
            ];
        }

        // A generic Via proves a proxy, not a cache — unless it names one.
        $via = $headers->get('Via');
        if ($via !== null && $via !== '') {
            $isVarnish = stripos($via, 'varnish') !== false;
            $findings[] = [
                'level' => self::LEVEL_WARNING,
                'label' => $isVarnish ? 'Varnish' : 'Proxy (Via header)',
                'evidence' => $isVarnish
                    ? 'This request arrived with Via: ' . $this->truncate($via) . ' — Varnish identified itself.'
                    : 'This request arrived with Via: ' . $this->truncate($via) . ' — a proxy is in the request path. Whether it caches is unknown; treat it as unsafe unless you know otherwise.',
            ];
            if (!$isVarnish) {
                $findings[count($findings) - 1]['level'] = self::LEVEL_NOTICE;
            }
        }

        return $findings;
    }

    /**
     * Tier 2: page caches running inside Craft. These never see the
     * response's cache headers, so their own configuration is read directly
     * — which makes this the most reliable tier: it inspects real settings
     * rather than inferring from traffic.
     *
     * @return array<array{level: string, label: string, evidence: string}>
     */
    public function inspectPageCaches(): array
    {
        $findings = [];

        $blitzClass = '\putyourlightson\blitz\Blitz';
        if (!class_exists($blitzClass) || !Craft::$app->getPlugins()->isPluginEnabled('blitz')) {
            return $findings;
        }

        try {
            $blitzSettings = $blitzClass::$plugin->settings;
            $cachingEnabled = (bool)($blitzSettings->cachingEnabled ?? false);
            $cacheNonHtml = (bool)($blitzSettings->cacheNonHtmlResponses ?? false);

            if (!$cachingEnabled) {
                return $findings;
            }

            if ($cacheNonHtml) {
                // The setting that removes Blitz's incidental protection
                // (#30): Blitz decides what to store from the response
                // *format*, and the negotiated Markdown response is
                // FORMAT_RAW — spared only while this stays off. LLM Ready
                // opts out of Blitz caching at runtime as well, but this
                // environment should be treated as cache-fronted.
                $findings[] = [
                    'level' => self::LEVEL_WARNING,
                    'label' => 'Blitz (caching non-HTML responses)',
                    'evidence' => 'Blitz is installed with cacheNonHtmlResponses enabled. Blitz decides what to store from the response format, not Cache-Control, so this is the setting that lets non-HTML responses be stored under canonical URIs. LLM Ready opts out at runtime, but treat this environment as having a page cache in front.',
                ];
            } else {
                // On a Blitz cache hit the request never reaches Craft, so
                // User-Agent detection silently doesn't run for cached pages
                // — worth knowing even though nothing is served incorrectly.
                $findings[] = [
                    'level' => self::LEVEL_NOTICE,
                    'label' => 'Blitz page cache active',
                    'evidence' => 'Blitz is caching HTML pages. Its default configuration does not store the Markdown response, and LLM Ready opts out of Blitz caching at runtime — but an AI bot requesting a page Blitz has already cached receives the cached HTML, so User-Agent detection will not fire on those hits.',
                ];
            }
            // PHPStan resolves nothing through the dynamic class string and
            // so sees no throw above, but Yii's __get() raises
            // UnknownPropertyException for a component that isn't defined —
            // exactly the Blitz API change this guards against.
            // @phpstan-ignore catch.neverThrown
        } catch (\Throwable $e) {
            $findings[] = [
                'level' => self::LEVEL_NOTICE,
                'label' => 'Blitz (settings unreadable)',
                'evidence' => "Blitz is installed but its settings could not be read ({$e->getMessage()}). Check its cacheNonHtmlResponses setting manually.",
            ];
        }

        return $findings;
    }

    /**
     * Tier 3: the active probe — the only tier that produces actual proof.
     *
     * Requests a URL twice with a browser User-Agent and inspects the second
     * response for cache-hit evidence (`Age > 0`, `X-Cache: HIT`,
     * `CF-Cache-Status: HIT`). A hit proves HTML on canonical URLs is being
     * shared-cached. Proxy fingerprints on either response (e.g.
     * `CF-Cache-Status: DYNAMIC`, `Server: cloudflare`) are recorded even
     * without a hit, since they prove an edge is in the path.
     *
     * Defaults to the primary site's base URL, but accepts any URL — which
     * is what makes this tier useful across the dev/prod split: unlike the
     * passive tiers, which can only see the environment they run in, the
     * probe inspects the *probed server's* response headers. A developer in
     * a local environment can point it at the production URL and learn
     * whether the live edge is proxied or caching, without leaving dev.
     *
     * The result (which records the probed URL) is persisted for the
     * current environment.
     *
     * @return array{verdict: string, url: string, evidence: string[], message: string}
     */
    public function runProbe(?string $url = null): array
    {
        $url = $url !== null && trim($url) !== '' ? trim($url) : null;

        if ($url === null) {
            try {
                $url = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
            } catch (\Throwable) {
                // fall through to the error result below
            }
        }

        if (!$url) {
            $result = [
                'verdict' => self::PROBE_ERROR,
                'url' => '',
                'evidence' => [],
                'message' => 'No URL to probe — the primary site has no resolvable base URL, and none was supplied.',
            ];
            $this->persist($this->getCurrentEnvironment(), probe: $result);

            return $result;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $result = [
                'verdict' => self::PROBE_ERROR,
                'url' => $url,
                'evidence' => [],
                'message' => 'The probe URL must be a full http:// or https:// URL.',
            ];
            $this->persist($this->getCurrentEnvironment(), probe: $result);

            return $result;
        }

        try {
            $client = Craft::createGuzzleClient([
                'connect_timeout' => 5,
                'timeout' => 10,
                'allow_redirects' => ['max' => 3],
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => self::PROBE_USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);

            // Two requests to the identical URL: the first may warm the
            // cache, the second is the one that can prove a HIT. No
            // cache-buster — that would defeat the test.
            $client->get($url);
            $response = $client->get($url);
        } catch (\Throwable $e) {
            $result = [
                'verdict' => self::PROBE_ERROR,
                'url' => $url,
                'evidence' => [],
                'message' => "The probe request failed: {$e->getMessage()}",
            ];
            $this->persist($this->getCurrentEnvironment(), probe: $result);

            return $result;
        }

        $evidence = [];
        $hit = false;

        $age = $response->getHeaderLine('Age');
        if ($age !== '' && (int)$age > 0) {
            $hit = true;
            $evidence[] = "Age: {$age} — the response had been sitting in a cache for {$age} seconds.";
        }

        foreach (['X-Cache', 'X-Page-Cache'] as $name) {
            $value = $response->getHeaderLine($name);
            if ($value !== '') {
                if (stripos($value, 'hit') !== false) {
                    $hit = true;
                    $evidence[] = "{$name}: {$value} — a cache reported serving this response.";
                } else {
                    $evidence[] = "{$name}: {$value} — a cache layer is present.";
                }
            }
        }

        $xCacheHits = $response->getHeaderLine('X-Cache-Hits');
        if ($xCacheHits !== '' && (int)$xCacheHits > 0) {
            $hit = true;
            $evidence[] = "X-Cache-Hits: {$xCacheHits} — a Fastly-style cache reported a hit.";
        }

        $cfStatus = $response->getHeaderLine('CF-Cache-Status');
        if ($cfStatus !== '') {
            if (in_array(strtoupper($cfStatus), self::CF_CACHE_HIT_STATUSES, true)) {
                $hit = true;
                $evidence[] = "CF-Cache-Status: {$cfStatus} — Cloudflare served this from its cache.";
            } else {
                $evidence[] = "CF-Cache-Status: {$cfStatus} — Cloudflare is proxying this URL (no cache hit in this test).";
            }
        }

        $server = $response->getHeaderLine('Server');
        if (stripos($server, 'cloudflare') !== false && $cfStatus === '') {
            $evidence[] = "Server: {$server} — Cloudflare is proxying this URL.";
        }

        foreach (['Via', 'X-Varnish', 'X-Served-By'] as $name) {
            $value = $response->getHeaderLine($name);
            if ($value !== '') {
                $evidence[] = "{$name}: " . $this->truncate($value) . ' — a proxy or cache identified itself.';
            }
        }

        // An in-Craft page cache on the *probed* site is invisible to Tier 2
        // (which reads local plugin config), but Blitz announces itself by
        // appending to X-Powered-By — the one place it is visible remotely.
        $poweredBy = $response->getHeaderLine('X-Powered-By');
        if (stripos($poweredBy, 'blitz') !== false) {
            $evidence[] = 'X-Powered-By: ' . $this->truncate($poweredBy) . ' — the Blitz page cache is active on the probed site, serving cached HTML on canonical URLs.';
        }

        // s-maxage only ever addresses shared caches, so its presence means
        // the site is built to have one storing its HTML — even when the
        // cache itself stays silent.
        $cacheControl = $response->getHeaderLine('Cache-Control');
        if (stripos($cacheControl, 's-maxage') !== false) {
            $evidence[] = 'Cache-Control: ' . $this->truncate($cacheControl) . ' — the page declares itself cacheable by shared caches (s-maxage), a directive that only exists for a cache in front.';
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $evidence[] = "The probe received HTTP {$status} — header evidence above still applies, but the page itself did not load normally.";
        }

        if ($hit) {
            $verdict = self::PROBE_HIT;
            $message = 'Confirmed: the second request was served from a shared cache. HTML on canonical URLs is being shared-cached at the probed site — keep AI Bot User-Agent Detection off wherever that site runs.';
        } elseif ($evidence !== []) {
            $verdict = self::PROBE_PROXY;
            $message = 'A proxy or CDN answered this probe, but did not serve it from cache during the test. That does not prove HTML is never cached — a cache can miss twice for many reasons. If you know a cache sits in front, trust that over this result.';
        } else {
            $verdict = self::PROBE_NONE;
            $message = 'No cache evidence in the probe responses. Absence of evidence is not absence of a cache — a cache configured not to identify itself is invisible to this check.';
        }

        $result = [
            'verdict' => $verdict,
            'url' => $url,
            'evidence' => $evidence,
            'message' => $message,
        ];

        $this->persist($this->getCurrentEnvironment(), probe: $result);

        return $result;
    }

    /**
     * The scannable one-line verdict for the Cache check pane header — a
     * status dot plus short text, derived from the passive findings and the
     * last probe so the outcome reads without the prose.
     *
     * Levels: `evidence` (confident: shared cache confirmed by a probe hit
     * or a warning-level passive finding), `proxy` (something is in the
     * path but caching isn't proven), `none` (inconclusive — deliberately
     * never "safe": absence of evidence is not absence of a cache).
     *
     * @param array<array{level: string, label: string, evidence: string}> $findings
     * @param array{verdict: string, url: string, evidence: string[], message: string}|null $probe
     * @return array{level: string, text: string}
     */
    public function getStatus(array $findings, ?array $probe): array
    {
        $passiveWarning = false;
        $passiveNotice = false;
        foreach ($findings as $finding) {
            if ($finding['level'] === self::LEVEL_WARNING) {
                $passiveWarning = true;
            } else {
                $passiveNotice = true;
            }
        }

        if ($passiveWarning || ($probe !== null && $probe['verdict'] === self::PROBE_HIT)) {
            return [
                'level' => 'evidence',
                'text' => Craft::t('llm-ready', 'Shared cache detected — keep this setting off'),
            ];
        }

        if ($passiveNotice || ($probe !== null && $probe['verdict'] === self::PROBE_PROXY)) {
            return [
                'level' => 'proxy',
                'text' => Craft::t('llm-ready', 'Proxy or page-cache evidence — see details'),
            ];
        }

        return [
            'level' => 'none',
            'text' => Craft::t('llm-ready', 'No cache evidence — inconclusive'),
        ];
    }

    /**
     * All stored per-environment results, decoded.
     *
     * @return array<array{environment: string, findings: array, passiveDate: DateTime|null, probe: array|null, probeDate: DateTime|null}>
     */
    public function getStoredResults(): array
    {
        $results = [];

        /** @var CacheCheckRecord[] $records */
        $records = CacheCheckRecord::find()->orderBy(['environment' => SORT_ASC])->all();

        foreach ($records as $record) {
            $passive = $record->passiveData !== null ? Json::decodeIfJson($record->passiveData) : null;
            $probe = $record->probeData !== null ? Json::decodeIfJson($record->probeData) : null;

            $results[] = [
                'environment' => $record->environment,
                'findings' => is_array($passive) ? ($passive['findings'] ?? []) : [],
                'passiveDate' => $record->passiveDate !== null ? DateTimeHelper::toDateTime($record->passiveDate) ?: null : null,
                'probe' => is_array($probe) ? $probe : null,
                'probeDate' => $record->probeDate !== null ? DateTimeHelper::toDateTime($record->probeDate) ?: null : null,
            ];
        }

        return $results;
    }

    /**
     * Upsert the row for an environment, updating whichever of the passive /
     * probe payloads was supplied.
     */
    private function persist(string $environment, ?array $passive = null, ?array $probe = null): void
    {
        /** @var CacheCheckRecord|null $record */
        $record = CacheCheckRecord::find()->where(['environment' => $environment])->one();

        if ($record === null) {
            $record = new CacheCheckRecord();
            $record->environment = $environment;
        }

        $now = Db::prepareDateForDb(new DateTime());

        if ($passive !== null) {
            $record->passiveData = Json::encode($passive);
            $record->passiveDate = $now;
        }

        if ($probe !== null) {
            $record->probeData = Json::encode($probe);
            $record->probeDate = $now;
        }

        if (!$record->save()) {
            Craft::warning(
                "Could not save cache check result for environment {$environment}: "
                . implode('; ', $record->getFirstErrors()),
                __METHOD__,
            );
        }
    }

    /**
     * Whether a Cloudflare finding is already present (so a
     * `CDN-Loop: cloudflare` doesn't produce a duplicate).
     *
     * @param array<array{level: string, label: string, evidence: string}> $findings
     */
    private function alreadyFoundCloudflare(array $findings, string $cdnLoop): bool
    {
        if (stripos($cdnLoop, 'cloudflare') === false) {
            return false;
        }

        foreach ($findings as $finding) {
            if (str_starts_with($finding['label'], 'Cloudflare')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bound a header value for display as evidence.
     */
    private function truncate(string $value): string
    {
        return mb_strlen($value) > 100 ? mb_substr($value, 0, 100) . '…' : $value;
    }
}
