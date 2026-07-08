<?php

declare(strict_types=1);

namespace johnfmorton\llmready\controllers;

use Craft;
use craft\web\Controller;
use johnfmorton\llmready\LlmReady;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * CP controller for analytics dashboard
 */
class AnalyticsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $permission = $action->id === 'purge'
            ? LlmReady::PERMISSION_PURGE_ANALYTICS
            : LlmReady::PERMISSION_VIEW_ANALYTICS;

        $this->requirePermission($permission);

        return true;
    }

    /**
     * Resolve the site to report on, constrained to the sites the current user
     * may edit.
     *
     * The view-analytics permission is global, but analytics reveal per-site
     * request paths and bot activity, so an explicit `siteId` for a site the
     * user can't edit is rejected rather than served. When no site is
     * requested, fall back to the current site if it's editable, otherwise the
     * user's first editable site.
     *
     * @throws ForbiddenHttpException if the requested site isn't editable, or
     * the user has no editable sites at all.
     */
    private function resolveSiteId(?int $requestedSiteId): int
    {
        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();

        if (empty($editableSiteIds)) {
            throw new ForbiddenHttpException('You don’t have permission to view analytics for any site.');
        }

        if ($requestedSiteId !== null) {
            if (!in_array($requestedSiteId, $editableSiteIds, true)) {
                throw new ForbiddenHttpException('You don’t have permission to view analytics for this site.');
            }

            return $requestedSiteId;
        }

        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        if (in_array($currentSiteId, $editableSiteIds, true)) {
            return $currentSiteId;
        }

        return $editableSiteIds[0];
    }

    /**
     * Render the analytics dashboard
     */
    public function actionIndex(): Response
    {
        $plugin = LlmReady::getInstance();
        $settings = $plugin->getSettings();

        // Restrict analytics to sites the current user is allowed to edit.
        $editableSites = Craft::$app->getSites()->getEditableSites();
        $siteId = $this->resolveSiteId(null);
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $endDate = (new \DateTime())->format('Y-m-d 23:59:59');
        $startDate = (new \DateTime())->modify('-30 days')->format('Y-m-d 00:00:00');

        $analyticsService = $plugin->analyticsService;

        $data = [
            'totalRequests' => $analyticsService->getTotalRequests($site->id, $startDate, $endDate),
            'requestsOverTime' => $analyticsService->getRequestsOverTime($site->id, $startDate, $endDate),
            'requestsOverTimeByBot' => $analyticsService->getRequestsOverTimeByBot($site->id, $startDate, $endDate),
            'requestsOverTimeByType' => $analyticsService->getRequestsOverTimeByType($site->id, $startDate, $endDate),
            'botBreakdown' => $analyticsService->getBotBreakdown($site->id, $startDate, $endDate),
            'requestTypeBreakdown' => $analyticsService->getRequestTypeBreakdown($site->id, $startDate, $endDate),
            'mostAccessedPages' => $analyticsService->getMostAccessedPages($site->id, $startDate, $endDate),
        ];

        return $this->renderTemplate('llm-ready/analytics/index', [
            'settings' => $settings,
            'data' => $data,
            'sites' => $editableSites,
            'currentSiteId' => $siteId,
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
        $requestedSiteId = $request->getParam('siteId');
        $siteId = $this->resolveSiteId($requestedSiteId !== null ? (int) $requestedSiteId : null);
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

        $botName = $request->getParam('botName') ?: null;
        if ($botName !== null) {
            $botName = array_map('trim', explode(',', $botName));
        }
        $requestType = $request->getParam('requestType') ?: null;
        if ($requestType !== null) {
            $requestType = array_map('trim', explode(',', $requestType));
        }

        $analyticsService = LlmReady::getInstance()->analyticsService;

        return $this->asJson([
            'totalRequests' => $analyticsService->getTotalRequests($siteId, $startDate, $endDate, $botName, $requestType),
            'requestsOverTime' => $analyticsService->getRequestsOverTime($siteId, $startDate, $endDate, $granularity, $botName, $requestType),
            'requestsOverTimeByBot' => $analyticsService->getRequestsOverTimeByBot($siteId, $startDate, $endDate, $granularity, $botName, $requestType),
            'requestsOverTimeByType' => $analyticsService->getRequestsOverTimeByType($siteId, $startDate, $endDate, $granularity, $botName, $requestType),
            'botBreakdown' => $analyticsService->getBotBreakdown($siteId, $startDate, $endDate, $botName, $requestType),
            'requestTypeBreakdown' => $analyticsService->getRequestTypeBreakdown($siteId, $startDate, $endDate, $botName, $requestType),
            'mostAccessedPages' => $analyticsService->getMostAccessedPages($siteId, $startDate, $endDate, 20, $botName, $requestType),
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
}
