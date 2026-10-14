<?php

declare(strict_types=1);

namespace johnfmorton\llmready\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use johnfmorton\llmready\LlmReady;
use yii\console\ExitCode;

/**
 * Index file commands — `/llms.txt` and section listing pages
 */
class IndexesController extends Controller
{
    /**
     * @var string|null Handle of a single site to build. Defaults to every site.
     */
    public ?string $site = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'site';

        return $options;
    }

    /**
     * Build /llms.txt and every section listing page now and store them as
     * the copies to serve. Run it after deploying or clearing caches, so no
     * visitor waits on a cold build.
     */
    public function actionRegenerate(): int
    {
        $sitesService = Craft::$app->getSites();

        if ($this->site !== null) {
            $site = $sitesService->getSiteByHandle($this->site);
            if ($site === null) {
                $this->stderr("No site with the handle \"{$this->site}\".\n");

                return ExitCode::USAGE;
            }
            $sites = [$site];
        } else {
            $sites = $sitesService->getAllSites();
        }

        App::maxPowerCaptain();
        $plugin = LlmReady::getInstance();
        $plugin->seoService->reset();

        foreach ($sites as $site) {
            foreach ($plugin->indexCacheService->getIndexes($site) as [, $section]) {
                $label = $section === null
                    ? 'llms.txt'
                    : $plugin->markdownService->getListingBaseUri($section, $site) . '.md';
                $this->stdout("{$site->getName()}: {$label}... ");
                $start = microtime(true);

                // Wait out a build already in progress rather than skipping,
                // so the command finishes with every copy in place.
                $content = $plugin->indexCacheService->regenerate($site, $section, 300);

                if ($content === null) {
                    $this->stderr("timed out waiting for another build.\n");

                    return ExitCode::UNSPECIFIED_ERROR;
                }

                $links = substr_count($content, "\n- [");
                $this->stdout(sprintf("%d links in %.1fs\n", $links, microtime(true) - $start));
            }
        }

        return ExitCode::OK;
    }
}
