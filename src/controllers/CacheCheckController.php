<?php

declare(strict_types=1);

namespace johnfmorton\llmready\controllers;

use Craft;
use craft\web\Controller;
use johnfmorton\llmready\LlmReady;
use johnfmorton\llmready\utilities\CacheCheckUtility;
use yii\web\Response;

/**
 * Runs the active shared-cache probe (Tier 3 of the cache detection in #33).
 *
 * Deliberately a button-triggered action rather than something automatic:
 * it is the only tier that makes outbound HTTP requests.
 */
class CacheCheckController extends Controller
{
    /**
     * Probe a URL twice and report cache-hit evidence. Defaults to the
     * primary site's base URL; an explicit `url` body param lets a developer
     * in one environment probe another one's public URL (typically: check
     * the production edge from a local install).
     *
     * Admins pass the permission check implicitly; non-admins need access to
     * the LLM Ready Cache Check utility — the same people who can see the
     * results are the ones who can refresh them. The response only ever
     * reflects response *headers* of the probed URL, never its body.
     */
    public function actionProbe(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:' . CacheCheckUtility::id());

        $url = Craft::$app->getRequest()->getBodyParam('url');

        $service = LlmReady::getInstance()->cacheDetectionService;
        $probe = $service->runProbe(is_string($url) ? $url : null);

        // Re-render the whole pane (header + body): the header switches
        // between its quiet and warning-banner states depending on what the
        // probe found together with the passive tiers, which getCheckData()
        // re-evaluates on this request.
        $data = $service->getCheckData();
        $data['probe'] = $probe;
        $data['probeDate'] = new \DateTime();

        $html = Craft::$app->getView()->renderTemplate('llm-ready/_partials/cache-check-inner', $data);

        return $this->asJson(['html' => $html]);
    }
}
