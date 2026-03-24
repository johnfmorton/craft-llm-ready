<?php

declare(strict_types=1);

namespace johnfmorton\llmready\controllers;

use Craft;
use craft\web\Controller;
use johnfmorton\llmready\LlmReady;
use yii\web\Response;

/**
 * CP controller for analytics dashboard
 */
class AnalyticsController extends Controller
{
    /**
     * Render the analytics dashboard
     */
    public function actionIndex(): Response
    {
        $plugin = LlmReady::getInstance();
        $settings = $plugin->getSettings();
        $site = Craft::$app->getSites()->getCurrentSite();

        $endDate = (new \DateTime())->format('Y-m-d 23:59:59');
        $startDate = (new \DateTime())->modify('-30 days')->format('Y-m-d 00:00:00');

        $analyticsService = $plugin->analyticsService;

        $data = [
            'totalRequests' => $analyticsService->getTotalRequests($site->id, $startDate, $endDate),
            'requestsOverTime' => $analyticsService->getRequestsOverTime($site->id, $startDate, $endDate),
            'botBreakdown' => $analyticsService->getBotBreakdown($site->id, $startDate, $endDate),
            'requestTypeBreakdown' => $analyticsService->getRequestTypeBreakdown($site->id, $startDate, $endDate),
            'mostAccessedPages' => $analyticsService->getMostAccessedPages($site->id, $startDate, $endDate),
        ];

        return $this->renderTemplate('llm-ready/analytics/index', [
            'settings' => $settings,
            'data' => $data,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'currentSiteId' => $site->id,
            'selectedRange' => '30',
        ]);
    }

    /**
     * JSON endpoint for AJAX date range / site changes
     */
    public function actionData(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $siteId = (int) ($request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $range = $request->getParam('range', '30');

        $endDate = (new \DateTime())->format('Y-m-d 23:59:59');

        if ($range === 'all') {
            $startDate = '2000-01-01 00:00:00';
        } else {
            $days = (int) $range;
            $startDate = (new \DateTime())->modify("-{$days} days")->format('Y-m-d 00:00:00');
        }

        $granularity = match (true) {
            $range === 'all' || (int) $range > 90 => 'month',
            (int) $range > 30 => 'week',
            default => 'day',
        };

        $analyticsService = LlmReady::getInstance()->analyticsService;

        return $this->asJson([
            'totalRequests' => $analyticsService->getTotalRequests($siteId, $startDate, $endDate),
            'requestsOverTime' => $analyticsService->getRequestsOverTime($siteId, $startDate, $endDate, $granularity),
            'botBreakdown' => $analyticsService->getBotBreakdown($siteId, $startDate, $endDate),
            'requestTypeBreakdown' => $analyticsService->getRequestTypeBreakdown($siteId, $startDate, $endDate),
            'mostAccessedPages' => $analyticsService->getMostAccessedPages($siteId, $startDate, $endDate),
        ]);
    }

    /**
     * Purge old analytics data
     */
    public function actionPurge(): Response
    {
        $this->requirePostRequest();

        $settings = LlmReady::getInstance()->getSettings();
        $deleted = LlmReady::getInstance()->analyticsService->purgeOldData($settings->analyticsRetentionDays);

        Craft::$app->getSession()->setNotice("Purged {$deleted} analytics records.");

        return $this->redirect('llm-ready');
    }

    /**
     * Render the settings page within the CP section
     */
    public function actionSettings(): Response
    {
        return $this->redirect('settings/plugins/llm-ready');
    }
}
