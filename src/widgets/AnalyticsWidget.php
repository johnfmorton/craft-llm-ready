<?php

declare(strict_types=1);

namespace johnfmorton\llmready\widgets;

use Craft;
use craft\base\Widget;
use johnfmorton\llmready\LlmReady;

/**
 * Compact dashboard widget showing last-30-day LLM Ready analytics summary.
 *
 * Reuses the existing AnalyticsService methods — no separate data layer.
 * Hidden from the dashboard widget picker when analytics are disabled or
 * the current user lacks the view-analytics permission.
 */
class AnalyticsWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('llm-ready', 'LLM Ready Analytics');
    }

    public static function icon(): ?string
    {
        return null;
    }

    public static function isSelectable(): bool
    {
        if (!parent::isSelectable()) {
            return false;
        }

        if (!LlmReady::getInstance()->getSettings()->enableAnalytics) {
            return false;
        }

        return self::currentUserCanView();
    }

    private static function currentUserCanView(): bool
    {
        return Craft::$app->getUser()->checkPermission(LlmReady::PERMISSION_VIEW_ANALYTICS);
    }

    public function getTitle(): ?string
    {
        if (!$this->currentUserCanView()) {
            return null;
        }
        return Craft::t('llm-ready', 'LLM Ready · last 30 days');
    }

    public function getBodyHtml(): ?string
    {
        $plugin = LlmReady::getInstance();

        if (!$plugin->getSettings()->enableAnalytics) {
            return null;
        }

        // Re-check permission at render time so that revoking the view-analytics
        // permission immediately stops disclosure, even for widgets already
        // saved on a user's dashboard.
        if (!$this->currentUserCanView()) {
            return null;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $endDate = (new \DateTime())->format('Y-m-d 23:59:59');
        $startDate = (new \DateTime())->modify('-30 days')->format('Y-m-d 00:00:00');

        $analyticsService = $plugin->analyticsService;
        $total = $analyticsService->getTotalRequests($site->id, $startDate, $endDate);
        $bots = $analyticsService->getBotBreakdown($site->id, $startDate, $endDate);
        $pages = $analyticsService->getMostAccessedPages($site->id, $startDate, $endDate, 1);

        return Craft::$app->getView()->renderTemplate('llm-ready/widgets/analytics', [
            'total' => $total,
            'topBot' => $bots[0] ?? null,
            'topPage' => $pages[0] ?? null,
            'siteName' => $site->name,
        ]);
    }
}
